<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetTest extends TestCase
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

    public function test_manager_can_create_view_update_and_delete_asset(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $createResponse = $this->postJson('/api/v1/assets', [
            'name' => 'Forklift 01',
            'serial_number' => 'FORK-0001',
            'asset_type' => 'vehicle',
        ], $this->tenantHeaders($project));

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.serial_number', 'FORK-0001');
        $systemId = $createResponse->json('data.system_id');
        $this->assertStringStartsWith('AST-', $systemId);

        $this->getJson("/api/v1/assets/{$systemId}", $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonPath('data.name', 'Forklift 01');

        $this->putJson("/api/v1/assets/{$systemId}", [
            'name' => 'Forklift 01 Updated',
        ], $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonPath('data.name', 'Forklift 01 Updated');

        $this->deleteJson("/api/v1/assets/{$systemId}", [], $this->tenantHeaders($project))
            ->assertOk();

        $this->assertSoftDeleted('assets', ['system_id' => $systemId]);
    }

    public function test_viewer_cannot_create_asset(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $this->postJson('/api/v1/assets', [
            'name' => 'Should Fail',
            'serial_number' => 'X-0001',
        ], $this->tenantHeaders($project))->assertForbidden();
    }

    public function test_viewer_can_list_assets(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->getJson('/api/v1/assets', $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_asset_endpoints_require_project_context(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $this->getJson('/api/v1/assets?organization_id=' . $project->organization_id)
            ->assertStatus(422);
    }

    public function test_serial_number_must_be_unique_within_project(): void
    {
        $project = Project::factory()->create();
        Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'serial_number' => 'DUP-0001',
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $this->postJson('/api/v1/assets', [
            'name' => 'Duplicate',
            'serial_number' => 'DUP-0001',
        ], $this->tenantHeaders($project))->assertJsonValidationErrors('serial_number');
    }

    public function test_serial_number_can_duplicate_across_projects(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        Asset::factory()->create([
            'organization_id' => $projectA->organization_id,
            'project_id' => $projectA->id,
            'serial_number' => 'SAME-0001',
        ]);

        $this->actingAsProjectUser($projectB, 'manager');

        $this->postJson('/api/v1/assets', [
            'name' => 'Same serial, different project',
            'serial_number' => 'SAME-0001',
        ], $this->tenantHeaders($projectB))->assertCreated();
    }

    public function test_system_id_is_immutable(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $asset = Asset::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
        $originalSystemId = $asset->system_id;

        $this->putJson("/api/v1/assets/{$asset->system_id}", [
            'system_id' => 'AST-HACKED',
            'name' => 'Renamed',
        ], $this->tenantHeaders($project))->assertOk();

        $this->assertEquals($originalSystemId, $asset->fresh()->system_id);
    }

    public function test_user_cannot_see_or_access_assets_from_another_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $assetB = Asset::factory()->create([
            'organization_id' => $projectB->organization_id,
            'project_id' => $projectB->id,
        ]);
        Asset::factory()->create([
            'organization_id' => $projectA->organization_id,
            'project_id' => $projectA->id,
        ]);

        $this->actingAsProjectUser($projectA, 'manager');

        // Listing while scoped to project A only ever returns project A's asset
        $this->getJson('/api/v1/assets', $this->tenantHeaders($projectA))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Direct lookup of project B's asset while scoped to project A: the
        // TenantScope global scope filters it out before it's even found.
        $this->getJson("/api/v1/assets/{$assetB->system_id}", $this->tenantHeaders($projectA))
            ->assertNotFound();
    }
}
