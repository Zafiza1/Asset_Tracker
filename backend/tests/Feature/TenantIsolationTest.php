<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_other_organization_data()
    {
        // Create two organizations
        $orgA = Organization::factory()->create(['name' => 'Organization A', 'slug' => 'org-a']);
        $orgB = Organization::factory()->create(['name' => 'Organization B', 'slug' => 'org-b']);

        // Create users for each organization
        $userA = User::factory()->create(['email' => 'usera@example.com']);
        $userB = User::factory()->create(['email' => 'userb@example.com']);

        // Attach users to organizations
        $orgA->users()->attach($userA->id, ['role' => 'member']);
        $orgB->users()->attach($userB->id, ['role' => 'member']);

        // Create projects for each organization
        $projectA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'name' => 'Project A',
            'slug' => 'project-a',
        ]);

        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'name' => 'Project B',
            'slug' => 'project-b',
        ]);

        // User A should be able to access their own organization
        $this->assertTrue($userA->canAccessOrganization($orgA->id));
        $this->assertFalse($userA->canAccessOrganization($orgB->id));

        // User B should be able to access their own organization
        $this->assertTrue($userB->canAccessOrganization($orgB->id));
        $this->assertFalse($userB->canAccessOrganization($orgA->id));

        // Test organization scoping
        $orgAProjects = Project::forOrganization($orgA->id)->get();
        $this->assertCount(1, $orgAProjects);
        $this->assertEquals($projectA->id, $orgAProjects->first()->id);

        $orgBProjects = Project::forOrganization($orgB->id)->get();
        $this->assertCount(1, $orgBProjects);
        $this->assertEquals($projectB->id, $orgBProjects->first()->id);
    }

    public function test_user_cannot_access_other_project_data()
    {
        // Create organization
        $org = Organization::factory()->create(['name' => 'Test Organization', 'slug' => 'test-org']);

        // Create user
        $user = User::factory()->create(['email' => 'user@example.com']);
        $org->users()->attach($user->id, ['role' => 'member']);

        // Create multiple projects
        $project1 = Project::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Project 1',
            'slug' => 'project-1',
        ]);

        $project2 = Project::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Project 2',
            'slug' => 'project-2',
        ]);

        // Attach user only to project 1
        $project1->users()->attach($user->id, ['role' => 'viewer']);

        // User should have access to project 1 but not project 2
        $this->assertTrue($user->canAccessProject($project1->id));
        $this->assertFalse($user->canAccessProject($project2->id));

        // Test project scoping
        $project1Data = Project::forProject($project1->id)->get();
        $this->assertCount(1, $project1Data);
        $this->assertEquals($project1->id, $project1Data->first()->id);
    }

    public function test_organization_slug_is_unique()
    {
        Organization::factory()->create(['slug' => 'test-org']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        
        Organization::factory()->create(['slug' => 'test-org']);
    }

    public function test_project_slug_is_unique_within_organization()
    {
        $org = Organization::factory()->create(['slug' => 'test-org']);

        Project::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'test-project',
        ]);

        // Same slug in same organization should fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        
        Project::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'test-project',
        ]);
    }

    public function test_project_slug_can_duplicate_across_organizations()
    {
        $orgA = Organization::factory()->create(['slug' => 'org-a']);
        $orgB = Organization::factory()->create(['slug' => 'org-b']);

        // Same slug in different organizations should work
        $projectA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'slug' => 'test-project',
        ]);

        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'slug' => 'test-project',
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $projectA->id,
            'slug' => 'test-project',
            'organization_id' => $orgA->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $projectB->id,
            'slug' => 'test-project',
            'organization_id' => $orgB->id,
        ]);
    }

    public function test_user_organization_role_check()
    {
        $org = Organization::factory()->create(['slug' => 'test-org']);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $org->users()->attach($user->id, ['role' => 'admin']);

        $this->assertTrue($user->hasOrganizationRole('admin', $org->id));
        $this->assertFalse($user->hasOrganizationRole('owner', $org->id));
    }

    public function test_user_project_role_check()
    {
        $org = Organization::factory()->create(['slug' => 'test-org']);
        $user = User::factory()->create(['email' => 'user@example.com']);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'slug' => 'test-project',
        ]);

        $project->users()->attach($user->id, ['role' => 'manager']);

        $this->assertTrue($user->hasProjectRole('manager', $project->id));
        $this->assertFalse($user->hasProjectRole('admin', $project->id));
    }

    public function test_organization_status_scoping()
    {
        Organization::factory()->create(['status' => 'active', 'slug' => 'active-org']);
        Organization::factory()->create(['status' => 'suspended', 'slug' => 'suspended-org']);
        Organization::factory()->create(['status' => 'active', 'slug' => 'active-org-2']);

        $activeOrgs = Organization::active()->get();
        $this->assertCount(2, $activeOrgs);

        $suspendedOrgs = Organization::suspended()->get();
        $this->assertCount(1, $suspendedOrgs);
    }

    public function test_project_status_scoping()
    {
        $org = Organization::factory()->create(['slug' => 'test-org']);

        Project::factory()->create([
            'organization_id' => $org->id,
            'status' => 'active',
            'slug' => 'active-project',
        ]);

        Project::factory()->create([
            'organization_id' => $org->id,
            'status' => 'archived',
            'slug' => 'archived-project',
        ]);

        Project::factory()->create([
            'organization_id' => $org->id,
            'status' => 'active',
            'slug' => 'active-project-2',
        ]);

        $activeProjects = Project::active()->get();
        $this->assertCount(2, $activeProjects);

        $archivedProjects = Project::archived()->get();
        $this->assertCount(1, $archivedProjects);
    }
}
