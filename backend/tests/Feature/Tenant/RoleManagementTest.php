<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->actingAs($this->admin, 'web');
    }

    public function test_new_organization_has_role_templates_matching_the_matrix(): void
    {
        $roles = $this->getJson('/api/roles')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(array_keys(RoleTemplates::tenant()), array_column($roles, 'code'));
        foreach ($roles as $role) {
            $this->assertEqualsCanonicalizing(RoleTemplates::tenant()[$role['code']]['permissions'], $role['permissions']);
        }
        $orgAdmin = collect($roles)->firstWhere('code', 'ORG_ADMIN');
        $this->assertTrue($orgAdmin['is_locked']);
        $this->assertSame(1, $orgAdmin['users_count']);
    }

    public function test_role_templates_only_reference_known_tenant_permissions(): void
    {
        foreach (RoleTemplates::tenant() as $template) {
            foreach ($template['permissions'] as $code) {
                $this->assertTrue(PermissionCatalog::exists($code, PermissionCatalog::SCOPE_TENANT), $code);
            }
        }
        foreach (RoleTemplates::platform() as $template) {
            foreach ($template['permissions'] as $code) {
                $this->assertTrue(PermissionCatalog::exists($code, PermissionCatalog::SCOPE_PLATFORM), $code);
            }
        }
    }

    public function test_permission_catalog_endpoint_lists_only_tenant_permissions(): void
    {
        $codes = array_column($this->getJson('/api/permissions')->assertOk()->json('data'), 'code');

        $this->assertEqualsCanonicalizing(PermissionCatalog::codes(PermissionCatalog::SCOPE_TENANT), $codes);
    }

    public function test_create_update_and_delete_custom_role(): void
    {
        $id = $this->postJson('/api/roles', [
            'code' => 'gudang', 'name' => 'Petugas Gudang', 'permissions' => ['asset.view', 'transaction.view'],
        ])->assertCreated()->assertJsonPath('data.code', 'GUDANG')->json('data.id');

        $this->patchJson("/api/roles/{$id}", ['name' => 'Petugas Gudang Pusat', 'permissions' => ['asset.view']])
            ->assertOk()->assertJsonPath('data.permissions', ['asset.view']);

        $update = AuditLog::query()->where('action', 'role.updated')->sole();
        $this->assertSame(['asset.view', 'transaction.view'], $update->before['permissions']);
        $this->assertSame(['asset.view'], $update->after['permissions']);

        $this->deleteJson("/api/roles/{$id}")->assertNoContent();
        $this->assertNull(Role::query()->find($id));
        $this->assertTrue(AuditLog::query()->where('action', 'role.deleted')->where('entity_id', $id)->exists());
    }

    public function test_role_code_must_be_unique_within_organization_only(): void
    {
        $this->createOrganization('BETA');

        $this->postJson('/api/roles', ['code' => 'VIEWER', 'name' => 'Dup', 'permissions' => []])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['code']]]]);
        $this->postJson('/api/roles', ['code' => 'KHUSUS', 'name' => 'Khusus', 'permissions' => []])->assertCreated();
    }

    public function test_locked_admin_role_permissions_cannot_change_or_be_deleted(): void
    {
        $orgAdmin = $this->roleId($this->org, 'ORG_ADMIN');

        $this->assertErrorCode($this->patchJson("/api/roles/{$orgAdmin}", ['permissions' => ['asset.view']]), 422, 'ROLE_LOCKED');
        $this->assertErrorCode($this->deleteJson("/api/roles/{$orgAdmin}"), 422, 'ROLE_LOCKED');
        $this->patchJson("/api/roles/{$orgAdmin}", ['name' => 'Admin Utama'])->assertOk();
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->createTenantUser($this->org, ['VIEWER']);

        $this->assertErrorCode($this->deleteJson('/api/roles/'.$this->roleId($this->org, 'VIEWER')), 409, 'ROLE_IN_USE');
    }

    public function test_unknown_or_platform_permissions_are_rejected(): void
    {
        $this->assertErrorCode(
            $this->postJson('/api/roles', ['code' => 'X1', 'name' => 'X', 'permissions' => ['asset.teleport']]),
            422, 'UNKNOWN_PERMISSION',
        );
        $this->assertErrorCode(
            $this->postJson('/api/roles', ['code' => 'X2', 'name' => 'X', 'permissions' => ['platform.organization.manage']]),
            422, 'UNKNOWN_PERMISSION',
        );
    }
}
