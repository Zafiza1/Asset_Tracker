<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    protected function actingAsProjectUser(Project $project, string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($project->organization_id);
        $user->projects()->attach($project->id);
        $user->assignRole($roleSlug, $project->organization_id, $project->id);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function tenantHeaders(Project $project): array
    {
        return [
            'X-Organization-Id' => $project->organization_id,
            'X-Project-Id' => $project->id,
        ];
    }

    public function test_project_admin_can_create_view_update_and_delete_location(): void
    {
        // 'manager' can create/update locations but not delete them (see
        // RoleAndPermissionSeeder) — project-admin covers the full CRUD set.
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'project-admin');

        $createResponse = $this->postJson('/api/v1/locations', [
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'address' => '123 Industrial Rd',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ], $this->tenantHeaders($project));

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.name', 'Main Warehouse');
        $locationId = $createResponse->json('data.id');

        $this->getJson("/api/v1/locations/{$locationId}", $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonPath('data.type', 'warehouse');

        $this->putJson("/api/v1/locations/{$locationId}", [
            'name' => 'Main Warehouse Updated',
        ], $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonPath('data.name', 'Main Warehouse Updated');

        $this->deleteJson("/api/v1/locations/{$locationId}", [], $this->tenantHeaders($project))
            ->assertOk();

        $this->assertSoftDeleted('locations', ['id' => $locationId]);
    }

    public function test_viewer_cannot_create_location(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $this->postJson('/api/v1/locations', [
            'name' => 'Should Fail',
        ], $this->tenantHeaders($project))->assertForbidden();
    }

    public function test_viewer_can_list_locations(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        Location::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->getJson('/api/v1/locations', $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_location_endpoints_require_project_context(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $this->getJson('/api/v1/locations?organization_id=' . $project->organization_id)
            ->assertStatus(422);
    }

    public function test_user_cannot_see_or_access_locations_from_another_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $locationB = Location::factory()->create([
            'organization_id' => $projectB->organization_id,
            'project_id' => $projectB->id,
        ]);
        Location::factory()->create([
            'organization_id' => $projectA->organization_id,
            'project_id' => $projectA->id,
        ]);

        $this->actingAsProjectUser($projectA, 'manager');

        $this->getJson('/api/v1/locations', $this->tenantHeaders($projectA))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/locations/{$locationB->id}", $this->tenantHeaders($projectA))
            ->assertNotFound();
    }
}
