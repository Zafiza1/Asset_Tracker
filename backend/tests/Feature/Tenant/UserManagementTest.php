<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
    }

    public function test_admin_creates_user_with_roles_scopes_and_temporary_password(): void
    {
        $branch = $this->branch($this->org, 'JKT');
        $this->actingAs($this->admin, 'web');

        $response = $this->postJson('/api/users', [
            'name' => 'Budi Operator',
            'email' => 'budi@alpha.test',
            'password' => 'Sementara-Rahasia-1',
            'employee_number' => 'EMP-001',
            'home_branch_id' => $branch->id,
            'role_ids' => [$this->roleId($this->org, 'OPERATOR')],
            'scopes' => [['scope_type' => 'branch', 'ref_id' => $branch->id]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'budi@alpha.test')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.roles.0.code', 'OPERATOR')
            ->assertJsonPath('data.data_scopes.0.scope_type', 'branch')
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'budi@alpha.test')->sole();
        $this->assertSame($this->org->id, $user->organization_id);
        $this->assertSame(User::TYPE_TENANT, $user->user_type);
        $this->assertTrue(Hash::check('Sementara-Rahasia-1', $user->password));
        $this->assertSame($this->admin->id, $user->created_by);

        $log = AuditLog::query()->where('action', 'user.created')->sole();
        $this->assertSame($user->id, $log->entity_id);
        $this->assertArrayNotHasKey('password', $log->after);
    }

    public function test_user_without_permission_cannot_create_users(): void
    {
        $operator = $this->createTenantUser($this->org, ['OPERATOR']);
        $this->actingAs($operator, 'web');

        $this->assertErrorCode($this->postJson('/api/users', []), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/users'), 403, 'FORBIDDEN');
    }

    public function test_validation_errors_use_standard_envelope(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/users', ['email' => 'bukan-email', 'role_ids' => [], 'scopes' => [['scope_type' => 'galaxy']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'email', 'password', 'role_ids', 'scopes.0.scope_type']]]]);
    }

    public function test_email_is_unique_across_all_organizations(): void
    {
        ['admin' => $adminB] = $this->createOrganization('BETA');
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/users', [
            'name' => 'Duplikat', 'email' => $adminB->email, 'password' => 'Sementara-Rahasia-1',
            'role_ids' => [$this->roleId($this->org, 'VIEWER')], 'scopes' => [['scope_type' => 'organization']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
    }

    public function test_scope_reference_from_another_organization_is_rejected(): void
    {
        ['organization' => $orgB] = $this->createOrganization('BETA');
        $branchB = $this->branch($orgB, 'SBY');
        $user = $this->createTenantUser($this->org, ['VIEWER']);
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode(
            $this->putJson("/api/users/{$user->id}/scopes", ['scopes' => [['scope_type' => 'branch', 'ref_id' => $branchB->id]]]),
            422, 'INVALID_SCOPE',
        );
        $this->assertSame(['organization'], $user->dataScopes()->pluck('scope_type')->all());
    }

    public function test_scopes_are_replaced_and_audited(): void
    {
        $branch = $this->branch($this->org, 'JKT');
        $user = $this->createTenantUser($this->org, ['VIEWER']);
        $this->actingAs($this->admin, 'web');

        $this->putJson("/api/users/{$user->id}/scopes", ['scopes' => [['scope_type' => 'branch', 'ref_id' => $branch->id]]])
            ->assertOk()->assertJsonPath('data.data_scopes', [['scope_type' => 'branch', 'ref_id' => $branch->id]]);

        $log = AuditLog::query()->where('action', 'user.scopes_changed')->sole();
        $this->assertSame(['organization'], $log->before['scopes']);
        $this->assertSame(["branch:{$branch->id}"], $log->after['scopes']);
    }

    public function test_last_organization_admin_cannot_be_removed(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode(
            $this->putJson("/api/users/{$this->admin->id}/roles", ['role_ids' => [$this->roleId($this->org, 'VIEWER')]]),
            409, 'LAST_ORG_ADMIN',
        );
        $this->assertSame(['ORG_ADMIN'], $this->admin->roles()->pluck('code')->all());
    }

    public function test_admin_cannot_change_own_status(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode($this->postJson("/api/users/{$this->admin->id}/suspend"), 422, 'CANNOT_CHANGE_OWN_STATUS');
    }

    public function test_second_admin_can_be_suspended_but_not_the_last_active_one(): void
    {
        $admin2 = $this->createTenantUser($this->org, ['ORG_ADMIN']);
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/users/{$admin2->id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/users/{$admin2->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        // admin2 now tries to suspend the original admin while being the only other admin: allowed,
        // because admin2 remains active.
        $this->actingAs($admin2, 'web');
        $this->postJson("/api/users/{$this->admin->id}/suspend")->assertOk();
        // ...but a suspended admin + the actor makes the actor the last one; deactivating the actor is impossible
        // (own status), and removing actor's admin role is blocked.
        $this->assertErrorCode(
            $this->putJson("/api/users/{$admin2->id}/roles", ['role_ids' => [$this->roleId($this->org, 'VIEWER')]]),
            409, 'LAST_ORG_ADMIN',
        );
    }

    public function test_deactivated_user_cannot_be_reactivated(): void
    {
        $user = $this->createTenantUser($this->org, ['VIEWER']);
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/users/{$user->id}/deactivate")->assertOk();
        $this->assertErrorCode($this->postJson("/api/users/{$user->id}/activate"), 409, 'USER_DEACTIVATED');
        $this->assertSame(User::STATUS_DEACTIVATED, $user->fresh()->status);
    }

    public function test_user_manager_cannot_escalate_privileges(): void
    {
        $userAdminRole = $this->customRole('USER_ADMIN', ['user.view', 'user.create', 'user.update', 'role.view', 'role.manage', 'organization.view']);
        $manager = $this->createTenantUser($this->org, []);
        $manager->roles()->attach($userAdminRole->id, ['organization_id' => $this->org->id]);
        $victim = $this->createTenantUser($this->org, ['VIEWER']);
        $this->actingAs($manager, 'web');

        // Cannot assign a role with permissions they lack (to others or themselves).
        $this->assertErrorCode(
            $this->putJson("/api/users/{$victim->id}/roles", ['role_ids' => [$this->roleId($this->org, 'ORG_ADMIN')]]),
            403, 'PRIVILEGE_ESCALATION',
        );
        $this->assertErrorCode(
            $this->putJson("/api/users/{$manager->id}/roles", ['role_ids' => [$userAdminRole->id, $this->roleId($this->org, 'ASSET_MANAGER')]]),
            403, 'PRIVILEGE_ESCALATION',
        );
        // Cannot create a role with permissions they lack, nor widen their own role.
        $this->assertErrorCode(
            $this->postJson('/api/roles', ['code' => 'SUPER', 'name' => 'Super', 'permissions' => ['audit.view']]),
            403, 'PRIVILEGE_ESCALATION',
        );
        $this->assertErrorCode(
            $this->patchJson("/api/roles/{$userAdminRole->id}", ['permissions' => ['user.view', 'settings.manage']]),
            403, 'PRIVILEGE_ESCALATION',
        );
        // Cannot reset the password of someone more privileged.
        $this->assertErrorCode(
            $this->postJson("/api/users/{$this->admin->id}/reset-password", ['password' => 'Baru-Rahasia-2026']),
            403, 'PRIVILEGE_ESCALATION',
        );

        $this->assertSame(['VIEWER'], $victim->roles()->pluck('code')->all());
        $this->assertFalse(Role::query()->where('code', 'SUPER')->exists());
    }

    public function test_reset_password_forces_change_and_is_audited(): void
    {
        $user = $this->createTenantUser($this->org, ['VIEWER']);
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/users/{$user->id}/reset-password", ['password' => 'Sementara-Baru-77'])->assertNoContent();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check('Sementara-Baru-77', $fresh->password));
        $log = AuditLog::query()->where('action', 'user.password_reset')->sole();
        $this->assertStringNotContainsString('Sementara-Baru-77', json_encode($log->getAttributes()));
    }

    public function test_user_list_supports_search_filter_and_safe_sorting(): void
    {
        $this->createTenantUser($this->org, ['VIEWER'], attributes: ['name' => 'Zainal Viewer']);
        $suspended = $this->createTenantUser($this->org, ['VIEWER'], attributes: ['name' => 'Ani Suspended']);
        $suspended->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($this->admin, 'web');

        $this->getJson('/api/users?search=zainal')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zainal Viewer');
        $this->getJson('/api/users?status=suspended')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/users?sort=-name')->assertOk()->assertJsonPath('data.0.name', 'Zainal Viewer');
        // Unknown sort keys fall back to the default instead of reaching SQL.
        $this->getJson('/api/users?sort=password;drop table users')->assertOk();
        $this->getJson('/api/users?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/users?per_page=1000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    private function branch(Organization $org, string $code): Branch
    {
        $branch = new Branch(['code' => $code, 'name' => "Cabang {$code}"]);
        $branch->forceFill(['organization_id' => $org->id])->save();

        return $branch;
    }

    /** @param list<string> $permissions */
    private function customRole(string $code, array $permissions): Role
    {
        $role = new Role(['code' => $code, 'name' => $code]);
        $role->forceFill(['organization_id' => $this->org->id])->save();
        $ids = Permission::query()->whereIn('code', $permissions)->pluck('id');
        $role->permissions()->attach($ids->mapWithKeys(fn ($id) => [$id => ['organization_id' => $this->org->id]])->all());

        return $role;
    }
}
