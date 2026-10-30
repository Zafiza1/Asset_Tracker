<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use App\Http\Support\ListQuery;
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

    public function test_adding_a_user_sends_an_invitation_and_creates_no_account(): void
    {
        $department = $this->department($this->org, 'OPS');
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/invitations', [
            'email' => 'budi@alpha.test',
            'employee_number' => 'EMP-001',
            'home_department_id' => $department->id,
            'role_ids' => [$this->roleId($this->org, 'SUPERVISOR')],
            'scopes' => [['scope_type' => 'department', 'ref_id' => $department->id]],
            'license_code_id' => $this->licenseCodeId($this->org),
        ])->assertCreated()
            ->assertJsonPath('data.email', 'budi@alpha.test')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.roles.0.code', 'SUPERVISOR')
            ->assertJsonPath('data.employee_number', 'EMP-001');

        // Direct account creation with a temporary password no longer exists.
        $this->assertFalse(User::query()->where('email', 'budi@alpha.test')->exists());
        $this->postJson('/api/users', ['email' => 'budi@alpha.test', 'password' => 'Sementara-Rahasia-1'])->assertStatus(405);
    }

    public function test_user_without_permission_cannot_create_users(): void
    {
        $operator = $this->createTenantUser($this->org, ['ADMIN']);
        $this->actingAs($operator, 'web');

        $this->assertErrorCode($this->postJson('/api/invitations', []), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/users'), 403, 'FORBIDDEN');
    }

    public function test_validation_errors_use_standard_envelope(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/invitations', ['email' => 'bukan-email', 'role_ids' => [], 'scopes' => [['scope_type' => 'galaxy']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'role_ids', 'scopes.0.scope_type']]]]);
    }

    /** SEC-01: entering the email of an existing account must not give that account access. */
    public function test_existing_account_is_invited_and_not_added_as_member(): void
    {
        ['organization' => $beta, 'admin' => $adminB] = $this->createOrganization('BETA');
        $this->actingAs($this->admin, 'web');
        $payload = [
            'email' => $adminB->email, 'job_title' => 'Konsultan',
            'role_ids' => [$this->roleId($this->org, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']], 'license_code_id' => $this->licenseCodeId($this->org),
        ];

        $this->postJson('/api/invitations', $payload)->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame([$beta->id], $adminB->memberships()->pluck('organization_id')->all());
        $this->assertSame([], $adminB->roles()->wherePivot('organization_id', $this->org->id)->pluck('code')->all());
        $this->assertTrue(Hash::check(self::PASSWORD, $adminB->fresh()->password));
        $this->assertFalse(AuditLog::query()->where('action', 'user.member_added')->exists());
        $this->assertErrorCode($this->getJson("/api/users/{$adminB->id}"), 404, 'NOT_FOUND');

        // Actual members are managed on their user page instead of being invited again.
        $member = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->assertErrorCode($this->postJson('/api/invitations', [...$payload, 'email' => $member->email]), 409, 'ALREADY_MEMBER');
    }

    public function test_shared_account_data_cannot_be_changed_by_one_organization(): void
    {
        ['organization' => $beta] = $this->createOrganization('BETA');
        $shared = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->addMembership($shared, $beta, 'SUPERVISOR');
        $this->actingAs($this->admin, 'web');

        // Membership data is per organization and stays editable.
        $this->patchJson("/api/users/{$shared->id}", ['job_title' => 'Supervisor'])->assertOk()->assertJsonPath('data.job_title', 'Supervisor');
        // Account data and password are shared with BETA.
        $this->assertErrorCode($this->patchJson("/api/users/{$shared->id}", ['name' => 'Diganti']), 422, 'SHARED_ACCOUNT');
        $this->assertErrorCode($this->postJson("/api/users/{$shared->id}/reset-password", ['password' => 'Sementara-Rahasia-9']), 422, 'SHARED_ACCOUNT');

        // Deactivating in ALPHA leaves the account usable in BETA.
        $this->postJson("/api/users/{$shared->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->assertSame(User::STATUS_ACTIVE, $shared->fresh()->status);
        $this->assertSame(User::STATUS_DEACTIVATED, $this->membership($shared)->status);
        $this->assertSame(User::STATUS_ACTIVE, OrganizationMember::query()->where('organization_id', $beta->id)->where('user_id', $shared->id)->value('status'));
        $this->assertTrue(Hash::check(self::PASSWORD, $shared->fresh()->password));
    }

    public function test_scope_reference_from_another_organization_is_rejected(): void
    {
        ['organization' => $orgB] = $this->createOrganization('BETA');
        $departmentB = $this->department($orgB, 'DIST');
        $user = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode(
            $this->putJson("/api/users/{$user->id}/scopes", ['scopes' => [['scope_type' => 'department', 'ref_id' => $departmentB->id]]]),
            422, 'INVALID_SCOPE',
        );
        $this->assertSame(['organization'], $user->dataScopes()->pluck('scope_type')->all());
    }

    public function test_scopes_are_replaced_and_audited(): void
    {
        $department = $this->department($this->org, 'OPS');
        $user = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->admin, 'web');

        $this->putJson("/api/users/{$user->id}/scopes", ['scopes' => [['scope_type' => 'department', 'ref_id' => $department->id]]])
            ->assertOk()->assertJsonPath('data.data_scopes', [['scope_type' => 'department', 'ref_id' => $department->id]]);

        $log = AuditLog::query()->where('action', 'user.scopes_changed')->sole();
        $this->assertSame(['organization'], $log->before['scopes']);
        $this->assertSame(["department:{$department->id}"], $log->after['scopes']);
    }

    public function test_last_organization_admin_cannot_be_removed(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode(
            $this->putJson("/api/users/{$this->admin->id}/roles", ['role_ids' => [$this->roleId($this->org, 'SUPERVISOR')]]),
            409, 'LAST_ORG_ADMIN',
        );
        $this->assertSame(['SUPER_ADMIN'], $this->admin->roles()->pluck('code')->all());
    }

    public function test_admin_cannot_change_own_status(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode($this->postJson("/api/users/{$this->admin->id}/deactivate"), 422, 'CANNOT_CHANGE_OWN_STATUS');
    }

    public function test_second_admin_can_be_deactivated_but_not_the_last_active_one(): void
    {
        $admin2 = $this->createTenantUser($this->org, ['SUPER_ADMIN']);
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/users/{$admin2->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->postJson("/api/users/{$admin2->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        // admin2 now tries to deactivate the original admin while being the only other admin: allowed,
        // because admin2 remains active.
        $this->actingAs($admin2, 'web');
        $this->postJson("/api/users/{$this->admin->id}/deactivate")->assertOk();
        // ...but a deactivated admin + the actor makes the actor the last one; deactivating the actor is impossible
        // (own status), and removing actor's admin role is blocked.
        $this->assertErrorCode(
            $this->putJson("/api/users/{$admin2->id}/roles", ['role_ids' => [$this->roleId($this->org, 'SUPERVISOR')]]),
            409, 'LAST_ORG_ADMIN',
        );
    }

    public function test_deactivated_user_can_be_reactivated(): void
    {
        $user = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/users/{$user->id}/deactivate")->assertOk();
        $this->postJson("/api/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(User::STATUS_ACTIVE, $this->membership($user)->status);
    }

    public function test_user_manager_cannot_escalate_privileges(): void
    {
        $userAdminRole = $this->customRole('USER_ADMIN', ['user.view', 'user.create', 'user.update', 'role.view', 'role.manage', 'organization.view']);
        $manager = $this->createTenantUser($this->org, []);
        $manager->roles()->attach($userAdminRole->id, ['organization_id' => $this->org->id]);
        $victim = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->actingAs($manager, 'web');

        // Cannot assign a role with permissions they lack (to others or themselves).
        $this->assertErrorCode(
            $this->putJson("/api/users/{$victim->id}/roles", ['role_ids' => [$this->roleId($this->org, 'ADMIN')]]),
            403, 'PRIVILEGE_ESCALATION',
        );
        $this->assertErrorCode(
            $this->putJson("/api/users/{$manager->id}/roles", ['role_ids' => [$userAdminRole->id, $this->customRole('MANAGER', ['asset.view', 'asset.create'])->id]]),
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

        $this->assertSame(['SUPERVISOR'], $victim->roles()->pluck('code')->all());
        $this->assertFalse(Role::query()->where('code', 'SUPER')->exists());
    }

    public function test_reset_password_forces_change_and_is_audited(): void
    {
        $user = $this->createTenantUser($this->org, ['SUPERVISOR']);
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
        $this->createTenantUser($this->org, ['SUPERVISOR'], attributes: ['name' => 'Zainal Viewer']);
        $deactivated = $this->createTenantUser($this->org, ['SUPERVISOR'], attributes: ['name' => 'Ani Deactivated']);
        $deactivated->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        $this->actingAs($this->admin, 'web');

        $this->getJson('/api/users?search=zainal')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zainal Viewer');
        $this->getJson('/api/users?status=deactivated')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/users?sort=-name')->assertOk()->assertJsonPath('data.0.name', 'Zainal Viewer');
        // Unknown sort keys fall back to the default instead of reaching SQL.
        $this->getJson('/api/users?sort=password;drop table users')->assertOk();
        $this->getJson('/api/users?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/users?per_page='.(ListQuery::MAX_PER_PAGE * 2))->assertOk()->assertJsonPath('meta.per_page', ListQuery::MAX_PER_PAGE);
    }

    private function membership(User $user): OrganizationMember
    {
        return OrganizationMember::query()->where('organization_id', $this->org->id)->where('user_id', $user->id)->sole();
    }

    private function department(Organization $org, string $code): Department
    {
        $department = new Department(['code' => $code, 'name' => "Departemen {$code}"]);
        $department->forceFill(['organization_id' => $org->id])->save();

        return $department;
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
