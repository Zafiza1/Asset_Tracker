<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\DeviceType;
use App\Models\Integration;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API keys (machine access) and the standard event contract endpoint.
 */
class ApiKeyAndEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        Http::fake(['*' => Http::response([], 200)]);

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function projectUser(string $role = 'project-admin', ?Project $project = null): User
    {
        $project ??= $this->project;
        $user = User::factory()->create();
        $user->organizations()->attach($project->organization_id);
        $user->projects()->attach($project->id);
        $user->assignRole($role, $project->organization_id, $project->id);

        return $user;
    }

    protected function headers(?Project $project = null): array
    {
        $project ??= $this->project;

        return ['X-Organization-Id' => $project->organization_id, 'X-Project-Id' => $project->id];
    }

    protected function issueKey(array $scopes, ?int $integrationId = null): string
    {
        Sanctum::actingAs($this->projectUser());

        $response = $this->withHeaders($this->headers())->postJson('/api/v1/api-keys', [
            'name' => 'Gateway',
            'scopes' => $scopes,
            'integration_id' => $integrationId,
        ])->assertStatus(201);

        // Subsequent requests authenticate with the key only.
        $this->app['auth']->forgetGuards();

        return $response->json('data.key');
    }

    protected function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ], $attributes));
    }

    public function test_api_key_is_shown_once_and_stored_hashed(): void
    {
        $plain = $this->issueKey(['event.ingest']);

        $this->assertStringStartsWith('atk_', $plain);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plain]);
        $this->assertDatabaseHas('api_keys', ['key_hash' => hash('sha256', $plain)]);

        Sanctum::actingAs($this->projectUser());
        $this->withHeaders($this->headers())->getJson('/api/v1/api-keys')
            ->assertOk()
            ->assertJsonMissingPath('data.0.key')
            ->assertJsonMissingPath('data.0.key_hash');
    }

    public function test_viewer_cannot_create_api_keys(): void
    {
        Sanctum::actingAs($this->projectUser('viewer'));

        $this->withHeaders($this->headers())->postJson('/api/v1/api-keys', [
            'name' => 'x', 'scopes' => ['event.ingest'],
        ])->assertStatus(403);
    }

    public function test_api_key_publishes_location_event_and_records_movement(): void
    {
        $asset = $this->asset();
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ]);
        $key = $this->issueKey(['event.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])->postJson('/api/v1/events', [
            'event' => 'asset.location.updated',
            'asset_id' => $asset->system_id,
            'location_id' => $location->id,
            'source' => 'erp',
        ])->assertStatus(202)->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('asset_movements', [
            'asset_id' => $asset->id,
            'to_location_id' => $location->id,
            'source' => 'erp',
        ]);
    }

    public function test_api_key_tenant_cannot_be_widened_by_headers(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        $foreignAsset = Asset::factory()->create([
            'organization_id' => $otherOrganization->id,
            'project_id' => $otherProject->id,
        ]);
        $key = $this->issueKey(['event.ingest']);

        $this->withHeaders(array_merge(['X-Api-Key' => $key], $this->headers($otherProject)))
            ->postJson('/api/v1/events', [
                'event' => 'asset.detected',
                'asset_id' => $foreignAsset->system_id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);

        $this->assertNull($foreignAsset->fresh()->last_seen_at);
    }

    public function test_invalid_or_revoked_key_is_rejected(): void
    {
        $this->withHeaders(['X-Api-Key' => 'atk_nope'])
            ->postJson('/api/v1/events', ['event' => 'asset.detected'])
            ->assertStatus(401);

        $key = $this->issueKey(['event.ingest']);
        $id = \App\Models\ApiKey::withoutGlobalScopes()->value('id');

        Sanctum::actingAs($this->projectUser());
        $this->withHeaders($this->headers())->deleteJson("/api/v1/api-keys/{$id}")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson('/api/v1/events', ['event' => 'asset.detected'])
            ->assertStatus(401);
    }

    public function test_key_without_scope_is_forbidden(): void
    {
        $key = $this->issueKey(['integration.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson('/api/v1/events', ['event' => 'asset.detected'])
            ->assertStatus(403);
    }

    public function test_reserved_core_events_cannot_be_published(): void
    {
        $key = $this->issueKey(['event.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson('/api/v1/events', ['event' => 'asset.created'])
            ->assertStatus(422);
    }

    public function test_status_event_updates_asset_through_core(): void
    {
        $asset = $this->asset(['status' => 'active']);
        $key = $this->issueKey(['event.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])->postJson('/api/v1/events', [
            'event' => 'asset.status.changed',
            'asset_id' => $asset->system_id,
            'metadata' => ['status' => 'maintenance'],
        ])->assertStatus(202);

        $this->assertSame('maintenance', $asset->fresh()->status);
    }

    public function test_custom_event_is_stored_and_forwarded_to_webhooks(): void
    {
        $webhook = Webhook::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'events' => ['maintenance.completed'],
        ]);
        $asset = $this->asset();
        $key = $this->issueKey(['event.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])->postJson('/api/v1/events', [
            'event' => 'maintenance.completed',
            'asset_id' => $asset->system_id,
            'source' => 'maintenance-module',
            'metadata' => ['work_order' => 'WO-1'],
        ])->assertStatus(202);

        $this->assertDatabaseHas('event_logs', ['event_type' => 'maintenance.completed', 'asset_id' => $asset->id]);
        $delivery = $webhook->deliveries()->first();
        $this->assertNotNull($delivery);
        $this->assertSame('WO-1', $delivery->payload['metadata']['work_order']);
    }

    public function test_user_with_permission_can_publish_events_and_viewer_cannot(): void
    {
        $asset = $this->asset();

        Sanctum::actingAs($this->projectUser('viewer'));
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/events', ['event' => 'asset.detected', 'asset_id' => $asset->system_id])
            ->assertStatus(403);

        Sanctum::actingAs($this->projectUser('project-admin'));
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/events', ['event' => 'asset.detected', 'asset_id' => $asset->system_id])
            ->assertStatus(202);

        $this->assertNotNull($asset->fresh()->last_seen_at);
    }

    public function test_generic_ingest_endpoint_routes_readings_to_integration(): void
    {
        $integration = Integration::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'type' => 'rfid',
            'status' => 'connected',
        ]);
        $device = Device::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'device_type_id' => DeviceType::factory()->create()->id,
            'integration_id' => $integration->id,
            'serial_number' => 'E200001722110144',
        ]);
        $asset = $this->asset();
        DeviceBinding::create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'project_id' => $this->project->id, 'bound_at' => now(),
        ]);
        $key = $this->issueKey(['integration.ingest'], $integration->id);

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson("/api/v1/integrations/{$integration->id}/ingest", [
                'readings' => [
                    ['tag_id' => 'E200001722110144', 'reader_id' => 'READER-01'],
                    ['reader_id' => 'READER-01'],
                ],
            ])
            ->assertStatus(202)
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonCount(1, 'data.errors');

        $this->assertDatabaseHas('event_logs', [
            'event_type' => 'asset.detected',
            'asset_id' => $asset->id,
            'status' => 'processed',
        ]);
    }

    public function test_integration_restricted_key_cannot_use_other_integration(): void
    {
        $allowed = Integration::factory()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'type' => 'rfid', 'status' => 'connected',
        ]);
        $other = Integration::factory()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'type' => 'gps', 'status' => 'connected',
        ]);
        $key = $this->issueKey(['integration.ingest'], $allowed->id);

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson("/api/v1/integrations/{$other->id}/ingest", ['device_id' => 'GPS-1', 'latitude' => 1, 'longitude' => 1])
            ->assertStatus(403);
    }

    public function test_api_key_cannot_reach_integration_of_another_tenant(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        $foreign = Integration::factory()->create([
            'organization_id' => $otherOrganization->id, 'project_id' => $otherProject->id,
            'type' => 'rfid', 'status' => 'connected',
        ]);
        $key = $this->issueKey(['integration.ingest']);

        $this->withHeaders(['X-Api-Key' => $key])
            ->postJson("/api/v1/integrations/{$foreign->id}/ingest", ['tag_id' => 'AAAA0000'])
            ->assertStatus(404);
    }
}
