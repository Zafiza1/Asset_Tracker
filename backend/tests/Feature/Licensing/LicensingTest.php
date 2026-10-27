<?php

namespace Tests\Feature\Licensing;

use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Organizations work under a base (Asset Tracker) license with 1 seat; seat packs add more.
 * Seats are a pool: active members and pending invitations use them, and suspending,
 * deactivating or revoking frees them for somebody else.
 */
class LicensingTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Base seat (the administrator) + 1 seat.
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA', seats: 1);
    }

    public function test_platform_records_the_license_with_a_new_organization(): void
    {
        $this->actingAs($this->createPlatformAdmin());

        $response = $this->postJson('/api/platform/organizations', [
            'code' => 'gamma', 'name' => 'PT Gamma', 'admin' => ['email' => 'admin@gamma.test'],
            'license' => ['expires_at' => now()->addYear()->toIso8601String(), 'seats' => 4, 'reference' => 'INV-9', 'notes' => 'Paket awal'],
        ])->assertCreated()
            ->assertJsonPath('data.license.active', true)
            ->assertJsonPath('data.license.seats.capacity', 5)
            // The first administrator's invitation already holds a seat.
            ->assertJsonPath('data.license.seats.used', 1);

        $this->getJson("/api/platform/organizations/{$response->json('data.id')}/licenses")->assertOk()
            ->assertJsonCount(2, 'data.licenses')
            ->assertJsonPath('data.licenses.0.reference', 'INV-9')
            ->assertJsonPath('data.licenses.0.notes', 'Paket awal');
        $this->getJson('/api/platform/organizations?search=gamma')->assertOk()->assertJsonPath('data.0.license.seats.capacity', 5);

        $this->postJson('/api/platform/organizations', ['code' => 'delta', 'name' => 'PT Delta', 'admin' => ['email' => 'a@delta.test']])
            ->assertStatus(422)->assertJsonPath('error.details.fields.license.0', 'The license field is required.');
    }

    public function test_pending_invitations_use_seats_and_revoking_one_frees_it(): void
    {
        $this->actingAs($this->admin);

        $first = $this->postJson('/api/invitations', $this->invite('a@alpha.test'))->assertCreated();
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('b@alpha.test')), 409, 'SEAT_LIMIT_REACHED');

        $this->postJson("/api/invitations/{$first->json('data.id')}/revoke")->assertOk();
        $this->postJson('/api/invitations', $this->invite('b@alpha.test'))->assertCreated();
        $this->getJson('/api/license')->assertOk()
            ->assertJsonPath('data.seats.capacity', 2)
            ->assertJsonPath('data.seats.active_members', 1)
            ->assertJsonPath('data.seats.pending_invitations', 1)
            ->assertJsonPath('data.seats.available', 0);
    }

    public function test_a_seat_is_not_tied_to_a_person(): void
    {
        $leaving = $this->createTenantUser($this->org, ['OPERATOR']);
        $this->actingAs($this->admin);
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('pengganti@alpha.test')), 409, 'SEAT_LIMIT_REACHED');

        // The member resigns: deactivating frees the seat for the replacement.
        $this->postJson("/api/users/{$leaving->id}/deactivate")->assertOk();
        $this->postJson('/api/invitations', $this->invite('pengganti@alpha.test'))->assertCreated();
    }

    public function test_reactivating_a_suspended_member_needs_a_free_seat(): void
    {
        $member = $this->createTenantUser($this->org, ['OPERATOR']);
        $this->actingAs($this->admin);

        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();
        $this->postJson('/api/invitations', $this->invite('baru@alpha.test'))->assertCreated();
        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/activate"), 409, 'SEAT_LIMIT_REACHED');
    }

    public function test_without_a_base_license_the_organization_is_read_only(): void
    {
        $member = $this->createTenantUser($this->org, ['OPERATOR']);
        $this->revokeAll(OrganizationLicense::KIND_BASE);
        $this->actingAs($this->admin);

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.seats.capacity', 0);
        $this->getJson('/api/users')->assertOk();
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'LICENSE_INACTIVE');
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('x@alpha.test')), 403, 'LICENSE_INACTIVE');
        // Freeing seats stays possible.
        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();
    }

    public function test_an_expired_license_ends_access_to_writes(): void
    {
        $this->actingAs($this->admin);
        $this->travel(13)->months();

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.active', false);
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'LICENSE_INACTIVE');
    }

    public function test_over_the_seat_limit_only_freeing_seats_is_allowed(): void
    {
        $member = $this->createTenantUser($this->org, ['OPERATOR']);
        $this->revokeAll(OrganizationLicense::KIND_SEATS);
        $this->actingAs($this->admin);

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.over_limit', true);
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'SEAT_LIMIT_EXCEEDED');

        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();
        $this->putJson('/api/organization', ['name' => 'Baru'])->assertOk();
    }

    public function test_an_invitation_cannot_be_accepted_once_the_license_ended(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/invitations', $this->invite('rina@luar.test'))->assertCreated();
        $token = $this->sentInvitationToken('rina@luar.test');
        $this->revokeAll(OrganizationLicense::KIND_BASE);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', [
            'token' => $token, 'name' => 'Rina', 'password' => 'Rahasia-Kuat-2026', 'password_confirmation' => 'Rahasia-Kuat-2026',
        ]), 409, 'ORGANIZATION_NO_SEAT');
        $this->assertFalse(User::query()->where('email', 'rina@luar.test')->exists());
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, OrganizationInvitation::query()->sole()->status);
    }

    public function test_platform_adds_and_revokes_licenses(): void
    {
        ['organization' => $beta] = $this->createOrganization('BETA');
        $this->actingAs($this->createPlatformAdmin());
        $url = "/api/platform/organizations/{$this->org->id}/licenses";

        $this->postJson($url, ['kind' => 'seats', 'expires_at' => now()->addYear()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('error.details.fields.seats.0', 'The seats field is required when kind is seats.');
        $this->postJson($url, ['kind' => 'seats', 'seats' => 3, 'expires_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('error.details.fields.expires_at.0', 'The expires at field must be a date after now.');
        $added = $this->postJson($url, ['kind' => 'seats', 'seats' => 3, 'expires_at' => now()->addYear()->toIso8601String(), 'reference' => 'INV-2'])
            ->assertCreated()->assertJsonPath('data.seats.capacity', 5);
        $licenseId = collect($added->json('data.licenses'))->firstWhere('reference', 'INV-2')['id'];

        $this->postJson("{$url}/{$licenseId}/revoke")
            ->assertStatus(422)->assertJsonPath('error.details.fields.reason.0', 'The reason field is required.');
        $this->postJson("{$url}/{$licenseId}/revoke", ['reason' => 'Salah input'])->assertOk()->assertJsonPath('data.seats.capacity', 2);
        $this->assertErrorCode($this->postJson("{$url}/{$licenseId}/revoke", ['reason' => 'Lagi']), 409, 'LICENSE_REVOKED');

        $betaLicense = OrganizationLicense::query()->where('organization_id', $beta->id)->value('id');
        $this->postJson("{$url}/{$betaLicense}/revoke", ['reason' => 'x'])->assertNotFound();

        $actions = DB::table('audit_logs')->where('organization_id', $this->org->id)->pluck('action')->all();
        $this->assertContains('license.granted', $actions);
        $this->assertContains('license.revoked', $actions);
    }

    public function test_only_the_platform_manages_licenses(): void
    {
        $this->actingAs($this->admin);
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$this->org->id}/licenses", [
            'kind' => 'seats', 'seats' => 100, 'expires_at' => now()->addYear()->toIso8601String(),
        ]), 403, 'PLATFORM_USER_REQUIRED');
        $this->getJson('/api/license')->assertOk()->assertJsonMissingPath('data.licenses.0.notes');

        $context = new TenantContext($this->org, $this->admin);
        $this->tenancy()->runAsTenant($context, function () {
            $this->assertSame(2, DB::table('organization_licenses')->count());
            $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->insert([
                'id' => (string) str()->uuid(), 'organization_id' => $this->org->id, 'kind' => 'seats', 'seats' => 100,
                'starts_at' => now(), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertSame(0, DB::table('organization_licenses')->update(['revoked_at' => now(), 'revoke_reason' => 'x']));
        });
        ['organization' => $beta] = $this->createOrganization('BETA');
        $this->tenancy()->runAsTenant($context, fn () => $this->assertSame(0, DB::table('organization_licenses')->where('organization_id', $beta->id)->count()));
    }

    public function test_license_terms_never_change_and_rows_are_never_deleted(): void
    {
        $license = OrganizationLicense::query()->where('organization_id', $this->org->id)->firstOrFail();

        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->update(['seats' => 999]));
        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->delete());
        $this->app->make(Licensing::class)->revoke($license, 'Uji');
        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->update(['revoke_reason' => 'lain']));
    }

    public function test_self_registered_organization_gets_a_trial(): void
    {
        // Use API token authentication instead of session for this test
        $this->postJson('/api/auth/register', [
            'organization_name' => 'PT Baru', 'organization_code' => 'baru', 'name' => 'Rina', 'email' => 'rina@baru.test',
            'password' => 'Rahasia-Kuat-2026', 'password_confirmation' => 'Rahasia-Kuat-2026',
        ])->assertCreated();

        $this->getJson('/api/license')->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.seats.capacity', 3)
            ->assertJsonPath('data.seats.used', 1)
            ->assertJsonPath('data.licenses.0.source', 'trial');
    }

    /** @return array<string, mixed> */
    private function invite(string $email): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->org, 'OPERATOR')], 'scopes' => [['scope_type' => 'organization']]];
    }

    private function revokeAll(string $kind): void
    {
        $licensing = $this->app->make(Licensing::class);
        OrganizationLicense::query()->where('organization_id', $this->org->id)->where('kind', $kind)->get()
            ->each(fn (OrganizationLicense $license) => $licensing->revoke($license, 'Uji'));
    }

    private function assertDatabaseFails(callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted the statement.');
    }
}
