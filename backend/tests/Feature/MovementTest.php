<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MovementTest extends TestCase
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

    public function test_manager_can_record_a_movement_and_asset_current_location_updates(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $asset = Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
        $warehouse = Location::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $response = $this->postJson("/api/v1/assets/{$asset->system_id}/movements", [
            'to_location_id' => $warehouse->id,
            'source' => 'manual',
        ], $this->tenantHeaders($project));

        $response->assertCreated();
        $response->assertJsonPath('data.to_location_id', $warehouse->id);
        $response->assertJsonPath('data.from_location_id', null);

        $this->assertDatabaseHas('asset_locations', [
            'asset_id' => $asset->id,
            'location_id' => $warehouse->id,
        ]);

        $show = $this->getJson("/api/v1/assets/{$asset->system_id}", $this->tenantHeaders($project));
        $show->assertOk();
        $show->assertJsonPath('data.current_location.id', $warehouse->id);
    }

    public function test_second_movement_auto_resolves_from_location_from_current_location(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $asset = Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
        $warehouseA = Location::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
        $warehouseB = Location::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->postJson("/api/v1/assets/{$asset->system_id}/movements", [
            'to_location_id' => $warehouseA->id,
        ], $this->tenantHeaders($project))->assertCreated();

        $second = $this->postJson("/api/v1/assets/{$asset->system_id}/movements", [
            'to_location_id' => $warehouseB->id,
        ], $this->tenantHeaders($project));

        $second->assertCreated();
        $second->assertJsonPath('data.from_location_id', $warehouseA->id);
        $second->assertJsonPath('data.to_location_id', $warehouseB->id);

        $this->assertDatabaseHas('asset_locations', [
            'asset_id' => $asset->id,
            'location_id' => $warehouseB->id,
        ]);

        $history = $this->getJson("/api/v1/assets/{$asset->system_id}/movements", $this->tenantHeaders($project));
        $history->assertOk();
        $history->assertJsonCount(2, 'data');
    }

    public function test_viewer_cannot_record_movement(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $asset = Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
        $location = Location::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->postJson("/api/v1/assets/{$asset->system_id}/movements", [
            'to_location_id' => $location->id,
        ], $this->tenantHeaders($project))->assertForbidden();
    }

    public function test_movement_rejects_location_from_another_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $this->actingAsProjectUser($projectA, 'manager');

        $asset = Asset::factory()->create([
            'organization_id' => $projectA->organization_id,
            'project_id' => $projectA->id,
        ]);
        $foreignLocation = Location::factory()->create([
            'organization_id' => $projectB->organization_id,
            'project_id' => $projectB->id,
        ]);

        $this->postJson("/api/v1/assets/{$asset->system_id}/movements", [
            'to_location_id' => $foreignLocation->id,
        ], $this->tenantHeaders($projectA))->assertJsonValidationErrors('to_location_id');
    }

    public function test_movement_rejects_asset_from_another_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $foreignAsset = Asset::factory()->create([
            'organization_id' => $projectB->organization_id,
            'project_id' => $projectB->id,
        ]);

        $this->actingAsProjectUser($projectA, 'manager');
        $location = Location::factory()->create([
            'organization_id' => $projectA->organization_id,
            'project_id' => $projectA->id,
        ]);

        $this->postJson("/api/v1/assets/{$foreignAsset->system_id}/movements", [
            'to_location_id' => $location->id,
        ], $this->tenantHeaders($projectA))->assertNotFound();
    }
}
