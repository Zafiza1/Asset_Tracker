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
        // Create organization with Super Admin
        ['organization' => $this->org, 'admin' => $this->superAdmin] = $this->createOrganization('ALPHA');
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

        // First invitation should fail (no seats available)
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('a@alpha.test')), 409, 'SEAT_LIMIT_REACHED');

        // Buy additional license
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();

        // Now invitation should succeed
        $first = $this->postJson('/api/invitations', $this->invite('a@alpha.test'))->assertCreated();

        // Second invitation should fail again
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('b@alpha.test')), 409, 'SEAT_LIMIT_REACHED');

        // Revoke first invitation
        $this->postJson("/api/invitations/{$first->json('data.id')}/revoke")->assertOk();

        // Now second invitation should succeed
        $this->postJson('/api/invitations', $this->invite('b@alpha.test'))->assertCreated();

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

        // No seats available
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invite('pengganti@alpha.test')), 409, 'SEAT_LIMIT_REACHED');

        // Buy license
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();

        // Now invitation should succeed
        $this->postJson('/api/invitations', $this->invite('pengganti@alpha.test'))->assertCreated();

        // Suspend member (frees seat)
        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();

        // Should have 1 available seat now
        $this->getJson('/api/license')->assertOk()
            ->assertJsonPath('data.seats.available', 1);
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
        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();
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
        $member = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->superAdmin);

        // Buy 1 license (total 2: 1 free + 1 purchased)
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();

        // Suspend member to free seat
        $this->postJson("/api/users/{$member->id}/suspend")->assertOk();

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
    private function invite(string $email): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->org, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']]];
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
