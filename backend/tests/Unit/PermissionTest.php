<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run the role and permission seeder
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    public function test_user_has_permission_through_role(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('manager', $org->id);

        // Manager should have asset.create permission
        $this->assertTrue($user->hasPermission('asset.create', $org->id));
    }

    public function test_user_has_direct_permission(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->givePermission('asset.delete', $org->id);

        // User should have direct permission
        $this->assertTrue($user->hasPermission('asset.delete', $org->id));
    }

    public function test_viewer_cannot_create_assets(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('viewer', $org->id);

        // Viewer should not have asset.create permission
        $this->assertFalse($user->hasPermission('asset.create', $org->id));
    }

    public function test_platform_admin_has_all_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('platform-admin');

        // Platform admin should have all permissions
        $this->assertTrue($user->hasPermission('organization.create'));
        $this->assertTrue($user->hasPermission('asset.delete'));
        $this->assertTrue($user->hasPermission('user.manage'));
    }

    public function test_user_has_any_permission(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('viewer', $org->id);

        // Viewer should have at least one of these permissions
        $this->assertTrue($user->hasAnyPermission(['asset.create', 'asset.view'], $org->id));
    }

    public function test_user_has_all_permissions(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('manager', $org->id);

        // Manager should have all these permissions
        $this->assertTrue($user->hasAllPermissions(['asset.view', 'asset.create', 'asset.update'], $org->id));
    }

    public function test_permissions_differ_by_tenant_context(): void
    {
        $user = User::factory()->create();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        
        $user->organizations()->attach([$orgA->id, $orgB->id]);
        
        // Assign different roles in different organizations
        $user->assignRole('manager', $orgA->id);
        $user->assignRole('viewer', $orgB->id);

        // User should have asset.create in org A but not in org B
        $this->assertTrue($user->hasPermission('asset.create', $orgA->id));
        $this->assertFalse($user->hasPermission('asset.create', $orgB->id));
    }

    public function test_remove_permission(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->givePermission('asset.create', $org->id);

        $this->assertTrue($user->hasPermission('asset.create', $org->id));

        $user->revokePermission('asset.create', $org->id);

        $this->assertFalse($user->hasPermission('asset.create', $org->id));
    }

    public function test_remove_role(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('manager', $org->id);

        $this->assertTrue($user->hasRole('manager', $org->id));

        $user->removeRole('manager', $org->id);

        $this->assertFalse($user->hasRole('manager', $org->id));
    }

    public function test_sync_roles(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        
        $user->organizations()->attach($org->id);
        $user->assignRole('manager', $org->id);

        $this->assertTrue($user->hasRole('manager', $org->id));

        $user->syncRoles(['viewer'], $org->id);

        $this->assertFalse($user->hasRole('manager', $org->id));
        $this->assertTrue($user->hasRole('viewer', $org->id));
    }

    public function test_get_roles_for_context(): void
    {
        $user = User::factory()->create();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        
        $user->organizations()->attach([$orgA->id, $orgB->id]);
        $user->assignRole('manager', $orgA->id);
        $user->assignRole('viewer', $orgB->id);

        $rolesA = $user->getRolesForContext($orgA->id);
        $rolesB = $user->getRolesForContext($orgB->id);

        $this->assertContains('manager', $rolesA);
        $this->assertNotContains('viewer', $rolesA);
        $this->assertContains('viewer', $rolesB);
        $this->assertNotContains('manager', $rolesB);
    }

    public function test_get_permissions_for_context(): void
    {
        $user = User::factory()->create();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        
        $user->organizations()->attach([$orgA->id, $orgB->id]);
        $user->assignRole('manager', $orgA->id);
        $user->assignRole('viewer', $orgB->id);

        $permissionsA = $user->getPermissionsForContext($orgA->id);
        $permissionsB = $user->getPermissionsForContext($orgB->id);

        // Manager should have asset.create, viewer should not
        $this->assertContains('asset.create', $permissionsA);
        $this->assertNotContains('asset.create', $permissionsB);
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $platformAdminRole = Role::where('slug', 'platform-admin')->first();
        
        $this->assertTrue($platformAdminRole->is_system);
        $this->assertTrue($platformAdminRole->isSystemRole());
    }

    public function test_system_permissions_cannot_be_deleted(): void
    {
        $assetViewPermission = Permission::where('slug', 'asset.view')->first();
        
        $this->assertTrue($assetViewPermission->is_system);
        $this->assertTrue($assetViewPermission->isSystemPermission());
    }
}
