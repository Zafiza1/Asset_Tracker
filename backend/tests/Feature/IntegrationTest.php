<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationConfig;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationTest extends TestCase
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

    public function test_user_can_create_integration(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson('/api/v1/integrations', [
                'name' => 'Test RFID Integration',
                'type' => 'rfid',
                'provider' => 'test-provider',
                'config' => [
                    'endpoint' => 'https://test.example.com',
                    'api_key' => 'test-key',
                ],
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Test RFID Integration',
                    'type' => 'rfid',
                    'provider' => 'test-provider',
                ],
            ]);

        $this->assertDatabaseHas('integrations', [
            'name' => 'Test RFID Integration',
            'type' => 'rfid',
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->assertDatabaseHas('integration_configs', [
            'key' => 'endpoint',
            'value' => 'https://test.example.com',
        ]);
    }

    public function test_user_can_view_integrations(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        // Create integration in different project - should not be visible
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $otherProject->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson('/api/v1/integrations')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(1, 'data');
    }

    public function test_tenant_isolation_for_integrations(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        // Create integration in current project
        Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        // Create integration in different organization
        $otherOrg = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $otherOrg->id]);
        Integration::factory()->create([
            'organization_id' => $otherOrg->id,
            'project_id' => $otherProject->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson('/api/v1/integrations')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_view_single_integration(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson("/api/v1/integrations/{$integration->id}")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                ],
            ]);
    }

    public function test_user_can_update_integration(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->putJson("/api/v1/integrations/{$integration->id}", [
                'name' => 'Updated Integration Name',
                'config' => [
                    'new_key' => 'new_value',
                ],
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Integration Name',
                ],
            ]);

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Updated Integration Name',
        ]);
    }

    public function test_user_can_delete_integration(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->deleteJson("/api/v1/integrations/{$integration->id}")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('integrations', [
            'id' => $integration->id,
        ]);
    }

    public function test_integration_type_validation(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson('/api/v1/integrations', [
                'name' => 'Invalid Integration',
                'type' => 'invalid_type',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_secret_config_keys_are_identified(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        IntegrationConfig::create([
            'integration_id' => $integration->id,
            'key' => 'api_key',
            'value' => 'secret_value',
            'is_secret' => true,
        ]);

        IntegrationConfig::create([
            'integration_id' => $integration->id,
            'key' => 'endpoint',
            'value' => 'https://example.com',
            'is_secret' => false,
        ]);

        $this->assertDatabaseHas('integration_configs', [
            'integration_id' => $integration->id,
            'key' => 'api_key',
            'is_secret' => true,
        ]);

        $this->assertDatabaseHas('integration_configs', [
            'integration_id' => $integration->id,
            'key' => 'endpoint',
            'is_secret' => false,
        ]);
    }
}
