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
 * Licensing system without platform admin.
 * Super Admin (first user who registers) gets 1 free license.
 * Additional licenses can be purchased by Super Admin (per user, payment bypassed).
 * Each license = 1 user seat.
 */
class LicensingTest extends TestCase
{
    private Organization $org;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Create organization with Super Admin and only the free license
        ['organization' => $this->org, 'admin' => $this->superAdmin] = $this->createOrganization('ALPHA', seats: 0);
    }

    public function test_super_admin_gets_free_license(): void
    {
        $this->actingAs($this->superAdmin);

        $status = app(Licensing::class)->status($this->org->id);

        $this->assertTrue($status->active);
        $this->assertEquals(1, $status->capacity); // 1 free license for Super Admin
        $this->assertEquals(1, $status->activeMembers);
        $this->assertEquals(0, $status->available());
    }

    public function test_pending_invitations_use_seats_and_revoking_one_frees_it(): void
    {
        $this->actingAs($this->superAdmin);

        // Buying a license adds a seat and its license code
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();
        $code = $this->licenseCodeId($this->org);

        $first = $this->postJson('/api/invitations', $this->invite('a@alpha.test', $code))->assertCreated();

        // The pending invitation holds the seat, whatever code is given
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('b@alpha.test', $code)), 409, 'SEAT_LIMIT_REACHED');

        // Revoking the first invitation frees the seat
        $this->postJson("/api/invitations/{$first->json('data.id')}/revoke")->assertOk();
        $this->postJson('/api/invitations', $this->invite('b@alpha.test', $code))->assertCreated();

        $this->getJson('/api/license')->assertOk()
            ->assertJsonPath('data.seats.capacity', 2)
            ->assertJsonPath('data.seats.active_members', 1)
            ->assertJsonPath('data.seats.pending_invitations', 1)
            ->assertJsonPath('data.seats.available', 0);
    }

    public function test_a_seat_is_not_tied_to_a_person(): void
    {
        $member = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->superAdmin);

        // 2 licenses (1 free + 1 purchased), both seats used by the Super Admin and the member
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();
        $code = $this->licenseCodeId($this->org);
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('pengganti@alpha.test', $code)), 409, 'SEAT_LIMIT_REACHED');

        // Deactivating the member frees the seat for somebody else
        $this->postJson("/api/users/{$member->id}/deactivate")->assertOk();
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.seats.available', 1);

        $this->postJson('/api/invitations', $this->invite('pengganti@alpha.test', $code))->assertCreated();
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.seats.available', 0);
    }

    public function test_without_a_base_license_the_organization_is_read_only(): void
    {
        $member = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->revokeAll(OrganizationLicense::KIND_BASE);
        $this->actingAs($this->superAdmin);

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.seats.capacity', 0);
        $this->getJson('/api/users')->assertOk();
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'LICENSE_INACTIVE');
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('x@alpha.test')), 403, 'LICENSE_INACTIVE');
        // Freeing seats stays possible (but will fail due to no seats to free)
        $this->postJson("/api/users/{$member->id}/deactivate")->assertOk();
    }

    public function test_an_expired_license_ends_access_to_writes(): void
    {
        $this->actingAs($this->superAdmin);
        $this->travel(15)->years(); // Free license is 10 years

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.active', false);
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'LICENSE_INACTIVE');
    }

    public function test_over_the_seat_limit_only_freeing_seats_is_allowed(): void
    {
        // 2 members on the 1 free license
        $member = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->superAdmin);

        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.over_limit', true);
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'Baru']), 403, 'SEAT_LIMIT_EXCEEDED');

        // Buying seats stays possible: it is how the organization gets back within its limit
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.over_limit', false)->assertJsonPath('data.seats.available', 0);

        // So does freeing seats
        $this->postJson("/api/users/{$member->id}/deactivate")->assertOk();
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.seats.available', 1);
    }

    public function test_license_purchase_adds_capacity(): void
    {
        $this->actingAs($this->superAdmin);

        // Initial: 1 license (free for Super Admin)
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.seats.capacity', 1);

        // Buy 3 licenses
        $this->postJson('/api/licenses/purchase', ['quantity' => 3])->assertCreated();

        // Should have 4 licenses total
        $this->getJson('/api/license')->assertOk()->assertJsonPath('data.seats.capacity', 4);
    }

    public function test_license_terms_never_change_and_rows_are_never_deleted(): void
    {
        $license = OrganizationLicense::query()->where('organization_id', $this->org->id)->firstOrFail();

        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->update(['seats' => 999]));
        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->delete());
        $this->app->make(Licensing::class)->revoke($license, 'Uji');
        $this->assertDatabaseFails(fn () => DB::table('organization_licenses')->where('id', $license->id)->update(['revoke_reason' => 'lain']));
    }

    public function test_self_registered_organization_gets_super_admin_with_free_license(): void
    {
        $this->postJson('/api/auth/register', [
            'organization_name' => 'PT Baru', 'organization_code' => 'baru', 'name' => 'Rina', 'email' => 'rina@baru.test',
            'password' => 'Rahasia-Kuat-2026', 'password_confirmation' => 'Rahasia-Kuat-2026',
        ])->assertCreated();

        $this->getJson('/api/license')->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.seats.capacity', 1) // 1 free license for Super Admin
            ->assertJsonPath('data.seats.used', 1)
            ->assertJsonPath('data.licenses.0.source', 'purchase'); // Changed from trial to purchase
    }

    /** @return array<string, mixed> */
    private function invite(string $email, string $licenseCodeId = '00000000-0000-0000-0000-000000000000'): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->org, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $licenseCodeId];
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
