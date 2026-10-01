<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run the role and permission seeder
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    public function test_user_cannot_access_other_organization(): void
    {
        // Create two organizations
        $orgA = Organization::factory()->create(['name' => 'Organization A']);
        $orgB = Organization::factory()->create(['name' => 'Organization B']);

        // Create users for each organization
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Assign users to their respective organizations
        $userA->organizations()->attach($orgA->id);
        $userB->organizations()->attach($orgB->id);

        // Verify user A can access their organization
        $this->assertTrue($userA->canAccessOrganization($orgA->id));
        
        // Verify user A cannot access organization B
        $this->assertFalse($userA->canAccessOrganization($orgB->id));
        
        // Verify user B can access their organization
        $this->assertTrue($userB->canAccessOrganization($orgB->id));
        
        // Verify user B cannot access organization A
        $this->assertFalse($userB->canAccessOrganization($orgA->id));
    }

    public function test_user_cannot_access_other_project(): void
    {
        // Create two projects in different organizations
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        
        $projectA = Project::factory()->create(['organization_id' => $orgA->id]);
        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);

        // Create users for each project
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Assign users to their respective projects
        $userA->projects()->attach($projectA->id);
        $userB->projects()->attach($projectB->id);

        // Verify user A can access their project
        $this->assertTrue($userA->canAccessProject($projectA->id));
        
        // Verify user A cannot access project B
        $this->assertFalse($userA->canAccessProject($projectB->id));
        
        // Verify user B can access their project
        $this->assertTrue($userB->canAccessProject($projectB->id));
        
        // Verify user B cannot access project A
        $this->assertFalse($userB->canAccessProject($projectA->id));
    }

    public function test_tenant_scoping_prevents_cross_tenant_access(): void
    {
        // Create two organizations with projects
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        
        $projectA = Project::factory()->create(['organization_id' => $orgA->id]);
        $projectB = Project::factory()->create(['organization_id' => $orgB->id]);

        // Create user with access to org A only
        $user = User::factory()->create();
        $user->organizations()->attach($orgA->id);
        $user->projects()->attach($projectA->id);

        // Query projects scoped to org A
        $projectsInOrgA = Project::forOrganization($orgA->id)->get();
        $this->assertTrue($projectsInOrgA->contains($projectA));
        $this->assertFalse($projectsInOrgA->contains($projectB));

        // Query projects scoped to org B
        $projectsInOrgB = Project::forOrganization($orgB->id)->get();
        $this->assertTrue($projectsInOrgB->contains($projectB));
        $this->assertFalse($projectsInOrgB->contains($projectA));
    }

    public function test_roles_are_isolated_by_tenant(): void
    {
        // Create organization
        $org = Organization::factory()->create();
        
        // Create user
        $user = User::factory()->create();
        $user->organizations()->attach($org->id);

        // Assign role at organization level
        $user->assignRole('manager', $org->id);

        // Verify role exists at organization level
        $this->assertTrue($user->hasRole('manager', $org->id));
        
        // Verify role doesn't exist at different context
        $this->assertFalse($user->hasRole('manager', null, 999));
    }

    public function test_permissions_are_isolated_by_tenant(): void
    {
        // Create organization and project
        $org = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $org->id]);
        
        // Create user
        $user = User::factory()->create();
        $user->organizations()->attach($org->id);
        $user->projects()->attach($project->id);

        // Give permission at organization level
        $user->givePermission('asset.create', $org->id);

        // Verify permission exists at organization level
        $this->assertTrue($user->hasPermission('asset.create', $org->id));
        
        // Verify permission doesn't exist at different context
        $this->assertFalse($user->hasPermission('asset.create', null, 999));
    }

    public function test_platform_admin_can_access_all_organizations(): void
    {
        // Create multiple organizations
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        // Create platform admin
        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');

        // Platform admin should have access to all organizations
        $this->assertTrue($admin->isPlatformAdmin());
        
        // Platform admin bypasses organization access checks
        $this->assertTrue($admin->canAccessOrganization($orgA->id) || $admin->isPlatformAdmin());
        $this->assertTrue($admin->canAccessOrganization($orgB->id) || $admin->isPlatformAdmin());
        $this->assertTrue($admin->canAccessOrganization($orgC->id) || $admin->isPlatformAdmin());
    }

    public function test_user_can_belong_to_multiple_organizations(): void
    {
        // Create multiple organizations
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        // Create user
        $user = User::factory()->create();

        // Assign user to multiple organizations
        $user->organizations()->attach([$orgA->id, $orgB->id]);

        // Verify user can access both organizations
        $this->assertTrue($user->canAccessOrganization($orgA->id));
        $this->assertTrue($user->canAccessOrganization($orgB->id));
        $this->assertFalse($user->canAccessOrganization($orgC->id));
    }

    public function test_user_can_belong_to_multiple_projects(): void
    {
        // Create multiple projects
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $projectC = Project::factory()->create();

        // Create user
        $user = User::factory()->create();

        // Assign user to multiple projects
        $user->projects()->attach([$projectA->id, $projectB->id]);

        // Verify user can access both projects
        $this->assertTrue($user->canAccessProject($projectA->id));
        $this->assertTrue($user->canAccessProject($projectB->id));
        $this->assertFalse($user->canAccessProject($projectC->id));
    }
}
