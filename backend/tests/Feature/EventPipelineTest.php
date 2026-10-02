<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\DeviceType;
use App\Models\Integration;
use App\Models\IntegrationConfig;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use App\Services\GPSService;
use App\Services\RFIDService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end checks of the event pipeline: Core changes and integration
 * input must produce standard events, which reach the audit log and
 * webhooks. Also covers the security fixes made alongside it.
 */
class EventPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function actingAsProjectUser(string $roleSlug = 'project-admin'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id);
        $user->projects()->attach($this->project->id);
        $user->assignRole($roleSlug, $this->organization->id, $this->project->id);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function headers(): array
    {
        return [
            'X-Organization-Id' => $this->organization->id,
            'X-Project-Id' => $this->project->id,
        ];
    }

    protected function webhookFor(array $events): Webhook
    {
        return Webhook::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'events' => $events,
        ]);
    }

    protected function boundDevice(string $serial, string $type): array
    {
        $integration = Integration::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'type' => $type,
        ]);
        $device = Device::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'device_type_id' => DeviceType::factory()->create()->id,
            'integration_id' => $integration->id,
            'serial_number' => $serial,
        ]);
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ]);
        DeviceBinding::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'project_id' => $this->project->id,
            'bound_at' => now(),
        ]);

        return [$integration, $device, $asset];
    }

    public function test_creating_an_asset_triggers_webhook_and_activity_log(): void
    {
        $user = $this->actingAsProjectUser();
        $webhook = $this->webhookFor(['asset.created']);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/assets', ['serial_number' => 'SN-1', 'name' => 'Asset 1'])
            ->assertStatus(201);

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event_type' => 'asset.created',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'create',
            'resource_type' => 'Asset',
            'user_id' => $user->id,
            'project_id' => $this->project->id,
        ]);

        Http::assertSent(function ($request) use ($webhook) {
            return $request->hasHeader('X-Webhook-Signature',
                hash_hmac('sha256', $request->body(), $webhook->secret));
        });

        $this->assertNotEmpty($response->json('data.system_id'));
    }

    public function test_status_change_triggers_status_changed_event(): void
    {
        $this->actingAsProjectUser();
        $webhook = $this->webhookFor(['asset.status.changed']);
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'status' => 'active',
        ]);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/assets/{$asset->system_id}", ['status' => 'maintenance'])
            ->assertOk();

        $delivery = $webhook->deliveries()->where('event_type', 'asset.status.changed')->first();
        $this->assertNotNull($delivery);
        $this->assertSame('active', $delivery->payload['old_status']);
        $this->assertSame('maintenance', $delivery->payload['new_status']);
    }

    public function test_gps_first_fix_records_movement_and_location_event(): void
    {
        $this->actingAsProjectUser();
        $webhook = $this->webhookFor(['asset.location.updated']);
        [$integration, , $asset] = $this->boundDevice('GPS-0001', 'gps');

        // 0,0 is a valid coordinate and must not be rejected.
        $eventLog = app(GPSService::class)->processLocationUpdate($integration, [
            'device_id' => 'GPS-0001',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $this->assertSame('processed', $eventLog->fresh()->status);
        $this->assertSame($asset->id, $eventLog->fresh()->asset_id);
        $this->assertDatabaseHas('asset_movements', ['asset_id' => $asset->id, 'source' => 'gps']);
        $this->assertNotNull(AssetLocation::where('asset_id', $asset->id)->value('location_id'));
        $this->assertNotNull($asset->fresh()->last_seen_at);
        $this->assertTrue($webhook->deliveries()->where('event_type', 'asset.location.updated')->exists());
    }

    public function test_gps_location_endpoints_return_current_location_and_history(): void
    {
        $this->actingAsProjectUser();
        [$integration, , $asset] = $this->boundDevice('GPS-0002', 'gps');

        app(GPSService::class)->processLocationUpdate($integration, [
            'device_id' => 'GPS-0002', 'latitude' => -6.2, 'longitude' => 106.8,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/integrations/{$integration->id}/gps/asset-location?asset_id={$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.latitude', -6.2);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/integrations/{$integration->id}/gps/asset-location-history?asset_id={$asset->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_rfid_read_publishes_asset_detected(): void
    {
        $this->actingAsProjectUser();
        $webhook = $this->webhookFor(['asset.detected']);
        [$integration, $device, $asset] = $this->boundDevice('E2000017221101441890ABCD', 'rfid');

        $eventLog = app(RFIDService::class)->processTagRead($integration, [
            'tag_id' => 'E2000017221101441890ABCD',
            'reader_id' => 'READER-01',
        ]);

        $eventLog->refresh();
        $this->assertSame('asset.detected', $eventLog->event_type);
        $this->assertSame($asset->id, $eventLog->asset_id);
        $this->assertSame($device->id, $eventLog->device_id);
        $this->assertSame('processed', $eventLog->status);
        $this->assertNotNull($asset->fresh()->last_seen_at);

        $delivery = $webhook->deliveries()->where('event_type', 'asset.detected')->first();
        $this->assertNotNull($delivery);
        $this->assertSame($asset->system_id, $delivery->payload['system_id']);
    }

    public function test_bound_device_cannot_be_rebound_without_explicit_replace(): void
    {
        $this->actingAsProjectUser();
        [, $device, $first] = $this->boundDevice('TAG-0001', 'rfid');
        $second = Asset::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/devices/{$device->system_id}/bind", ['asset_id' => $second->id])
            ->assertStatus(409);

        $this->assertSame($first->id, $device->boundAsset()->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/devices/{$device->system_id}/bind", [
                'asset_id' => $second->id,
                'replace' => true,
                'reason' => 'tag damaged',
            ])
            ->assertOk();

        $this->assertSame($second->id, $device->fresh()->boundAsset()->id);
        $this->assertSame(1, DeviceBinding::where('device_id', $device->id)->whereNull('unbound_at')->count());
    }

    public function test_device_cannot_bind_asset_from_another_project(): void
    {
        $this->actingAsProjectUser();
        [, $device] = $this->boundDevice('TAG-0002', 'rfid');
        $otherProject = Project::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Asset::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $otherProject->id,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/devices/{$device->system_id}/bind", ['asset_id' => $foreign->id, 'replace' => true])
            ->assertStatus(422);
    }

    public function test_integration_secrets_are_encrypted_and_masked(): void
    {
        $this->actingAsProjectUser();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/integrations', [
                'name' => 'GPS provider',
                'type' => 'gps',
                'config' => ['endpoint' => 'https://gps.example.com', 'api_key' => 'super-secret-key'],
            ])
            ->assertStatus(201);

        $this->assertSame(IntegrationConfig::MASK, $response->json('data.config.api_key'));
        $this->assertSame('https://gps.example.com', $response->json('data.config.endpoint'));

        $raw = DB::table('integration_configs')->where('key', 'api_key')->value('value');
        $this->assertStringStartsWith(IntegrationConfig::ENCRYPTED_PREFIX, $raw);
        $this->assertStringNotContainsString('super-secret-key', $raw);

        $this->assertSame('super-secret-key', IntegrationConfig::where('key', 'api_key')->first()->value);
    }

    public function test_available_integrations_route_is_not_shadowed(): void
    {
        $this->actingAsProjectUser();

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/integrations/available')
            ->assertOk();
    }

    public function test_webhooks_accept_extension_events_but_reject_malformed_names(): void
    {
        $this->actingAsProjectUser();
        $payload = fn (array $events) => ['name' => 'ERP', 'endpoint' => 'https://erp.example.com/hook', 'events' => $events];

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/webhooks', $payload(['asset.detected', 'integration.degraded', 'maintenance.completed']))
            ->assertStatus(201);

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/webhooks', $payload(['Asset Created']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['events.0']);
    }

    public function test_webhook_secret_is_returned_once_and_encrypted_at_rest(): void
    {
        $this->actingAsProjectUser();

        $created = $this->withHeaders($this->headers())
            ->postJson('/api/v1/webhooks', [
                'name' => 'ERP',
                'endpoint' => 'https://erp.example.com/hook',
                'events' => ['asset.created'],
            ])
            ->assertStatus(201);

        $secret = $created->json('data.secret');
        $this->assertNotEmpty($secret);

        $raw = DB::table('webhooks')->where('id', $created->json('data.id'))->value('secret');
        $this->assertNotSame($secret, $raw);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/webhooks/' . $created->json('data.id'))
            ->assertOk()
            ->assertJsonMissingPath('data.secret');
    }
}
