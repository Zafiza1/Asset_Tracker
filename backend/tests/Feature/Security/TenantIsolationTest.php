<?php

namespace Tests\Feature\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

/**
 * HTTP-level tenant isolation (docs/07 §2, spec §16 multi-tenant scenarios).
 * Records of another tenant must be indistinguishable from non-existent ones (404).
 */
class TenantIsolationTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
    }

    public function test_user_list_only_contains_own_organization(): void
    {
        $this->createTenantUser($this->orgB, ['VIEWER']);
        $this->actingAs($this->adminA, 'web');

        $ids = collect($this->getJson('/api/users?per_page=100')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->adminA->id, $ids);
        $this->assertNotContains($this->adminB->id, $ids);
        $this->assertSame(
            User::query()->where('organization_id', $this->orgA->id)->count(),
            $ids->count(),
        );
    }

    public function test_cannot_read_or_modify_user_of_another_organization_by_guessing_id(): void
    {
        $this->actingAs($this->adminA, 'web');
        $target = $this->adminB->id;

        $this->assertErrorCode($this->getJson("/api/users/{$target}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/users/{$target}", ['name' => 'Dibajak']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/users/{$target}/suspend"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->putJson("/api/users/{$target}/roles", ['role_ids' => []]), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/users/{$target}/reset-password", ['password' => 'Baru-Rahasia-2026']), 404, 'NOT_FOUND');

        $fresh = $this->adminB->fresh();
        $this->assertSame('Admin BETA', $fresh->name);
        $this->assertSame(User::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, $fresh->roles()->count());
    }

    public function test_cannot_read_or_modify_role_of_another_organization(): void
    {
        $this->actingAs($this->adminA, 'web');
        $roleB = $this->roleId($this->orgB, 'VIEWER');

        $this->assertErrorCode($this->getJson("/api/roles/{$roleB}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/roles/{$roleB}", ['name' => 'X']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->deleteJson("/api/roles/{$roleB}"), 404, 'NOT_FOUND');

        $this->assertSame('Viewer', Role::query()->find($roleB)->name);
    }

    public function test_cannot_assign_role_of_another_organization(): void
    {
        $user = $this->createTenantUser($this->orgA, ['VIEWER']);
        $this->actingAs($this->adminA, 'web');

        $this->assertErrorCode(
            $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => [$this->roleId($this->orgB, 'ORG_ADMIN')]]),
            422, 'INVALID_ROLE',
        );
        $this->assertSame(['VIEWER'], $user->roles()->pluck('code')->all());
    }

    public function test_organization_id_in_request_body_is_ignored(): void
    {
        $this->actingAs($this->adminA, 'web');

        $this->putJson('/api/organization', ['name' => 'Alpha Baru', 'organization_id' => $this->orgB->id])
            ->assertOk()->assertJsonPath('data.id', $this->orgA->id);
        $this->postJson('/api/roles', ['code' => 'CUSTOM', 'name' => 'Custom', 'permissions' => [], 'organization_id' => $this->orgB->id])
            ->assertCreated();

        $this->assertSame('Alpha Baru', $this->orgA->fresh()->name);
        $this->assertSame('Organisasi BETA', $this->orgB->fresh()->name);
        $this->assertSame($this->orgA->id, Role::query()->where('code', 'CUSTOM')->sole()->organization_id);
    }

    public function test_organization_header_or_query_cannot_switch_tenant(): void
    {
        $this->actingAs($this->adminA, 'web');

        $this->withHeader('X-Organization-Id', $this->orgB->id)
            ->getJson('/api/organization?organization_id='.$this->orgB->id)
            ->assertOk()->assertJsonPath('data.id', $this->orgA->id);
    }

    public function test_audit_log_only_shows_own_organization(): void
    {
        $this->tenancy()->runAsSystem(function () {
            $this->app->make(AuditLogger::class)->record('test.beta_event', organizationId: $this->orgB->id);
        });
        $this->actingAs($this->adminA, 'web');

        $orgIds = collect($this->getJson('/api/audit-logs?per_page=100')->assertOk()->json('data'))->pluck('organization_id')->unique();

        $this->assertSame([$this->orgA->id], $orgIds->values()->all());
        $this->assertTrue(AuditLog::query()->where('action', 'test.beta_event')->exists());
    }

    public function test_tenant_administrator_cannot_use_platform_endpoints(): void
    {
        $this->actingAs($this->adminA, 'web');

        $this->assertErrorCode($this->getJson('/api/platform/organizations'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$this->orgB->id}/suspend", ['reason' => 'x']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/platform/audit-logs'), 403, 'FORBIDDEN');

        $this->assertSame(Organization::STATUS_ACTIVE, $this->orgB->fresh()->status);
    }

    public function test_platform_user_cannot_use_tenant_endpoints(): void
    {
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->assertErrorCode($this->getJson('/api/users'), 403, 'TENANT_USER_REQUIRED');
        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'TENANT_USER_REQUIRED');
    }

    public function test_access_is_revoked_immediately_when_user_is_suspended(): void
    {
        $user = $this->createTenantUser($this->orgA, ['VIEWER']);
        $this->actingAs($user, 'web');
        $this->getJson('/api/organization')->assertOk();

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertErrorCode($this->getJson('/api/organization'), 401, 'ACCOUNT_INACTIVE');
    }

    public function test_access_is_revoked_immediately_when_organization_is_suspended(): void
    {
        $this->actingAs($this->adminA, 'web');
        $this->getJson('/api/organization')->assertOk();

        $this->orgA->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();

        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'ORGANIZATION_INACTIVE');
    }

    public function test_permission_changes_apply_on_next_request(): void
    {
        $user = $this->createTenantUser($this->orgA, ['AUDITOR']);
        $this->actingAs($user, 'web');
        $this->getJson('/api/audit-logs')->assertOk();

        $user->roles()->detach();
        $user->roles()->attach($this->roleId($this->orgA, 'VIEWER'), ['organization_id' => $this->orgA->id]);

        $this->assertErrorCode($this->getJson('/api/audit-logs'), 403, 'FORBIDDEN');
    }

    public function test_forbidden_is_returned_before_existence_is_revealed(): void
    {
        $viewer = $this->createTenantUser($this->orgA, ['VIEWER']);
        $this->actingAs($viewer, 'web');

        // Same response for an id of the own tenant, another tenant, or a non-existent one.
        foreach ([$this->adminA->id, $this->adminB->id, '00000000-0000-0000-0000-000000000000'] as $id) {
            $this->assertErrorCode($this->getJson("/api/users/{$id}"), 403, 'FORBIDDEN');
        }
    }

    public function test_malformed_ids_return_404_not_server_error(): void
    {
        $this->actingAs($this->adminA, 'web');

        $this->assertErrorCode($this->getJson('/api/users/bukan-uuid'), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->getJson("/api/users/1' OR '1'='1"), 404, 'NOT_FOUND');
    }
}
