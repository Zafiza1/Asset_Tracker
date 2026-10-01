<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    public function test_public_registration_cannot_choose_a_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_slug' => 'platform-admin',
        ])->assertCreated();

        $user = User::where('email', 'mallory@example.com')->firstOrFail();
        $this->assertFalse($user->isPlatformAdmin());
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_project_must_belong_to_organization_in_context(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $user = User::factory()->create();
        $user->organizations()->attach([$projectA->organization_id, $projectB->organization_id]);
        $user->projects()->attach([$projectA->id, $projectB->id]);
        $user->assignRole('organization-owner', $projectA->organization_id);
        $user->assignRole('viewer', $projectB->organization_id, $projectB->id);
        Sanctum::actingAs($user);

        // Organization A (owner) + project B (viewer) must not combine into
        // owner rights over project B.
        $this->postJson('/api/v1/assets', ['name' => 'X', 'serial_number' => 'X-1'], [
            'X-Organization-Id' => $projectA->organization_id,
            'X-Project-Id' => $projectB->id,
        ])->assertForbidden();

        $this->assertFalse($user->hasPermission('asset.create', $projectB->organization_id, $projectB->id));
    }

    public function test_organization_is_derived_from_project_context(): void
    {
        $project = Project::factory()->create();
        Asset::factory()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id]);

        $user = User::factory()->create();
        $user->organizations()->attach($project->organization_id);
        $user->projects()->attach($project->id);
        $user->assignRole('viewer', $project->organization_id, $project->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/assets', ['X-Project-Id' => $project->id])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_stale_default_project_from_other_organization_is_ignored(): void
    {
        $projectA = Project::factory()->create();
        $organizationB = Organization::factory()->create();

        $user = User::factory()->create();
        $user->organizations()->attach([$projectA->organization_id, $organizationB->id]);
        $user->projects()->attach($projectA->id);
        $user->forceFill([
            'default_organization_id' => $projectA->organization_id,
            'default_project_id' => $projectA->id,
        ])->save();
        Sanctum::actingAs($user);

        // Explicit organization B, default project from A: no project context.
        $this->getJson('/api/v1/assets', ['X-Organization-Id' => $organizationB->id])
            ->assertStatus(422);
    }

    public function test_organization_roles_apply_to_that_organizations_projects_only(): void
    {
        $organization = Organization::factory()->create();
        $ownProject = Project::factory()->create(['organization_id' => $organization->id]);
        $foreignProject = Project::factory()->create();

        $user = User::factory()->create();
        $user->organizations()->attach($organization->id);
        $user->assignRole('manager', $organization->id);

        $this->assertTrue($user->canAccessProject($ownProject->id));
        $this->assertTrue($user->hasPermission('asset.create', $organization->id, $ownProject->id));
        $this->assertFalse($user->canAccessProject($foreignProject->id));
        $this->assertFalse($user->hasPermission('asset.create', $foreignProject->organization_id, $foreignProject->id));
    }

    public function test_plain_organization_member_does_not_get_project_access(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $user = User::factory()->create();
        $user->organizations()->attach($organization->id);

        $this->assertFalse($user->canAccessProject($project->id));
    }

    public function test_sync_roles_only_replaces_roles_in_the_given_context(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('manager', $organizationA->id);
        $user->assignRole('manager', $organizationB->id);

        $user->syncRoles(['viewer'], $organizationA->id);

        $this->assertTrue($user->hasRole('viewer', $organizationA->id));
        $this->assertFalse($user->hasRole('manager', $organizationA->id));
        $this->assertTrue($user->hasRole('manager', $organizationB->id));
    }
}
