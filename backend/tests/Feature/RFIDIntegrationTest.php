<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\RFIDService;
use App\Integrations\RFID\RFIDIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RFIDIntegrationTest extends TestCase
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

    public function test_can_register_rfid_tag(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/register-tag", [
                'tag_id' => 'E200341080380021',
                'name' => 'Test RFID Tag',
                'tag_type' => 'passive',
                'frequency' => 'uhf',
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'serial_number' => 'E200341080380021',
                    'name' => 'Test RFID Tag',
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'serial_number' => 'E200341080380021',
            'project_id' => $project->id,
        ]);
    }

    public function test_can_register_rfid_reader(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/register-reader", [
                'reader_id' => 'READER-001',
                'name' => 'Main Gate Reader',
                'reader_type' => 'fixed',
                'antenna_count' => 4,
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'serial_number' => 'READER-001',
                    'name' => 'Main Gate Reader',
                ],
            ]);
    }

    public function test_invalid_tag_id_format_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/register-tag", [
                'tag_id' => 'invalid-tag-id',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid tag ID format',
            ]);
    }

    public function test_can_ingest_tag_read(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/ingest-read", [
                'tag_id' => 'E200341080380021',
                'reader_id' => 'READER-001',
                'timestamp' => now()->toIso8601String(),
                'rssi' => -65,
                'location' => 'Warehouse A',
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'event_type' => 'asset.detected',
                    'tag_id' => 'E200341080380021',
                ],
            ]);

        $this->assertDatabaseHas('event_logs', [
            'event_type' => 'asset.detected',
            'source' => 'rfid',
            'integration_id' => $integration->id,
        ]);
    }

    public function test_tag_read_resolves_to_bound_asset(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        // Register tag as device
        $tagDevice = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'serial_number' => 'E200341080380021',
            'integration_id' => $integration->id,
        ]);

        // Create asset and bind
        $asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        DeviceBinding::create([
            'device_id' => $tagDevice->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'bound_at' => now(),
        ]);

        // Ingest tag read
        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/ingest-read", [
                'tag_id' => 'E200341080380021',
                'reader_id' => 'READER-001',
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'asset_id' => $asset->id,
                ],
            ]);

        $this->assertDatabaseHas('event_logs', [
            'event_type' => 'asset.detected',
            'asset_id' => $asset->id,
        ]);
    }

    public function test_can_bulk_register_tags(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/bulk-register-tags", [
                'tags' => [
                    ['tag_id' => 'E200341080380021', 'name' => 'Tag 1'],
                    ['tag_id' => 'E200341080380022', 'name' => 'Tag 2'],
                    ['tag_id' => 'E200341080380023', 'name' => 'Tag 3'],
                ],
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'success' => 3,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('devices', 3);
    }

    public function test_can_get_rfid_devices(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'integration_id' => $integration->id,
            'serial_number' => 'E200341080380021',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson("/api/v1/integrations/{$integration->id}/rfid/devices")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(1, 'data');
    }

    public function test_can_get_tag_read_stats(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson("/api/v1/integrations/{$integration->id}/rfid/stats")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_reads' => 0,
                    'unique_tags' => 0,
                    'resolved_assets' => 0,
                ],
            ]);
    }

    public function test_bulk_ingest_tag_reads(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/integrations/{$integration->id}/rfid/bulk-ingest-reads", [
                'reads' => [
                    ['tag_id' => 'E200341080380021', 'reader_id' => 'READER-001'],
                    ['tag_id' => 'E200341080380022', 'reader_id' => 'READER-001'],
                ],
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'processed' => 2,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('event_logs', 2);
    }

    public function test_rfid_integration_contract_implements_interface(): void
    {
        $rfidIntegration = new RFIDIntegration();

        $this->assertInstanceOf(\App\Integrations\Contracts\IntegrationContract::class, $rfidIntegration);
        $this->assertEquals('rfid', $rfidIntegration->getType());
        $this->assertEquals('RFID Integration', $rfidIntegration->getName());
    }

    public function test_rfid_event_normalization(): void
    {
        $rfidIntegration = new RFIDIntegration();

        $rawData = [
            'tag_id' => 'E200341080380021',
            'reader_id' => 'READER-001',
            'timestamp' => '2026-09-30T10:00:00Z',
            'rssi' => -65,
            'location' => 'Warehouse A',
        ];

        $normalized = $rfidIntegration->normalize($rawData);

        $this->assertEquals('asset.detected', $normalized['event_type']);
        $this->assertEquals('E200341080380021', $normalized['payload']['tag_id']);
        $this->assertEquals('READER-001', $normalized['payload']['reader_id']);
        $this->assertEquals('rfid', $normalized['metadata']['source_type']);
    }

    public function test_rfid_config_validation(): void
    {
        $rfidIntegration = new RFIDIntegration();

        $validConfig = [
            'endpoint' => 'https://rfid.example.com',
            'api_key' => 'test-key',
        ];

        $result = $rfidIntegration->validateConfig($validConfig);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        $invalidConfig = [
            'endpoint' => 'not-a-url',
        ];

        $result = $rfidIntegration->validateConfig($invalidConfig);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('endpoint', $result['errors']);
        $this->assertArrayHasKey('api_key', $result['errors']);
    }
}
