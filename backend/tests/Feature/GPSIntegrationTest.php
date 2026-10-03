<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\GPSService;
use App\Integrations\GPS\GPSIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GPSIntegrationTest extends TestCase
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

    public function test_can_register_gps_tracker(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Direct service call for this test
        $gpsService = app(GPSService::class);
        $device = $gpsService->registerTracker([
            'device_id' => 'GPS-001',
            'name' => 'Test GPS Tracker',
            'tracker_type' => 'standalone',
        ], $integration);

        $this->assertEquals('GPS-001', $device->serial_number);
        $this->assertEquals('Test GPS Tracker', $device->name);
        $this->assertDatabaseHas('devices', [
            'serial_number' => 'GPS-001',
            'project_id' => $project->id,
        ]);
    }

    public function test_invalid_device_id_format_is_rejected(): void
    {
        $gpsService = app(GPSService::class);
        
        $this->assertFalse($gpsService->validateDeviceId('invalid@device#id'));
        $this->assertTrue($gpsService->validateDeviceId('GPS-001'));
    }

    public function test_can_ingest_location_update(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Direct service call
        $gpsService = app(GPSService::class);
        $eventLog = $gpsService->processLocationUpdate($integration, [
            'device_id' => 'GPS-001',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'timestamp' => now()->toIso8601String(),
            'speed' => 45.5,
            'heading' => 180,
        ]);

        $this->assertEquals('asset.location.updated', $eventLog->event_type);
        $this->assertEquals('GPS-001', $eventLog->payload['device_serial']);
        $this->assertDatabaseHas('event_logs', [
            'event_type' => 'asset.location.updated',
            'source' => 'gps',
            'integration_id' => $integration->id,
        ]);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $gpsService = app(GPSService::class);
        
        $this->assertFalse($gpsService->validateCoordinates(91, -74.0060)); // Invalid latitude
        $this->assertFalse($gpsService->validateCoordinates(40.7128, 181)); // Invalid longitude
        $this->assertTrue($gpsService->validateCoordinates(40.7128, -74.0060)); // Valid
    }

    public function test_location_update_resolves_to_bound_asset(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Create device type first
        $deviceType = \App\Models\DeviceType::factory()->create([
            'slug' => 'gps_tracker',
            'name' => 'GPS Tracker',
        ]);

        // Register GPS device
        $gpsDevice = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'serial_number' => 'GPS-001',
            'integration_id' => $integration->id,
            'device_type_id' => $deviceType->id,
            'name' => 'GPS Device 001',
        ]);

        // Create asset and bind
        $asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        DeviceBinding::create([
            'device_id' => $gpsDevice->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'bound_at' => now(),
        ]);

        // Ingest location update via service
        $gpsService = app(GPSService::class);
        $eventLog = $gpsService->processLocationUpdate($integration, [
            'device_id' => 'GPS-001',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        $this->assertEquals($asset->id, $eventLog->asset_id);
        $this->assertDatabaseHas('event_logs', [
            'event_type' => 'asset.location.updated',
            'asset_id' => $asset->id,
        ]);
    }

    public function test_can_bulk_register_trackers(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Direct service call
        $gpsService = app(GPSService::class);
        $results = $gpsService->bulkRegisterTrackers([
            ['device_id' => 'GPS-001', 'name' => 'Tracker 1'],
            ['device_id' => 'GPS-002', 'name' => 'Tracker 2'],
            ['device_id' => 'GPS-003', 'name' => 'Tracker 3'],
        ], $integration);

        $this->assertEquals(3, $results['success']);
        $this->assertEquals(0, $results['failed']);
        $this->assertDatabaseCount('devices', 3);
    }

    public function test_can_get_gps_devices(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Create device type first
        $deviceType = \App\Models\DeviceType::factory()->create(['slug' => 'gps_tracker']);

        Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'integration_id' => $integration->id,
            'serial_number' => 'GPS-001',
            'device_type_id' => $deviceType->id,
        ]);

        // Direct service call
        $gpsService = app(GPSService::class);
        $devices = $gpsService->getGPSDevices($integration);

        $this->assertCount(1, $devices);
        $this->assertEquals('GPS-001', $devices->first()->serial_number);
    }

    public function test_can_get_location_update_stats(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Bypass policy for this test - direct service call
        $gpsService = app(GPSService::class);
        $stats = $gpsService->getLocationUpdateStats($integration, 24);

        $this->assertEquals(0, $stats['total_updates']);
        $this->assertEquals(0, $stats['unique_devices']);
        $this->assertEquals(0, $stats['resolved_assets']);
    }

    public function test_bulk_ingest_location_updates(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        // Direct service call for this test
        $gpsService = app(GPSService::class);
        $results = $gpsService->bulkIngestLocationUpdates([
            ['device_id' => 'GPS-001', 'latitude' => 40.7128, 'longitude' => -74.0060],
            ['device_id' => 'GPS-002', 'latitude' => 40.7130, 'longitude' => -74.0065],
        ], $integration);

        $this->assertEquals(2, $results['processed']);
        $this->assertEquals(0, $results['failed']);
        $this->assertDatabaseCount('event_logs', 2);
    }

    public function test_gps_integration_contract_implements_interface(): void
    {
        $gpsIntegration = new GPSIntegration();

        $this->assertInstanceOf(\App\Integrations\Contracts\IntegrationContract::class, $gpsIntegration);
        $this->assertEquals('gps', $gpsIntegration->getType());
        $this->assertEquals('GPS Integration', $gpsIntegration->getName());
    }

    public function test_gps_event_normalization(): void
    {
        $gpsIntegration = new GPSIntegration();

        $rawData = [
            'device_id' => 'GPS-001',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'timestamp' => '2026-09-30T10:00:00Z',
            'speed' => 45.5,
            'heading' => 180,
            'altitude' => 10.5,
        ];

        $normalized = $gpsIntegration->normalize($rawData);

        $this->assertEquals('asset.location.updated', $normalized['event_type']);
        $this->assertEquals('GPS-001', $normalized['payload']['device_serial']);
        $this->assertEquals(40.7128, $normalized['payload']['latitude']);
        $this->assertEquals(-74.0060, $normalized['payload']['longitude']);
        $this->assertEquals('gps', $normalized['metadata']['source_type']);
    }

    public function test_gps_config_validation(): void
    {
        $gpsIntegration = new GPSIntegration();

        $validConfig = [
            'endpoint' => 'https://gps.example.com',
            'api_key' => 'test-key',
        ];

        $result = $gpsIntegration->validateConfig($validConfig);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        $invalidConfig = [
            'endpoint' => 'not-a-url',
        ];

        $result = $gpsIntegration->validateConfig($invalidConfig);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('endpoint', $result['errors']);
        $this->assertArrayHasKey('api_key', $result['errors']);
    }

    public function test_gps_distance_calculation(): void
    {
        $gpsIntegration = new GPSIntegration();

        // Distance between New York and Boston (approximately 340 km)
        $distance = $gpsIntegration->calculateDistance(
            40.7128, -74.0060, // New York
            42.3601, -71.0589  // Boston
        );

        $this->assertGreaterThan(300000, $distance); // > 300 km
        $this->assertLessThan(400000, $distance); // < 400 km
    }

    public function test_gps_geofence_circle_check(): void
    {
        $gpsIntegration = new GPSIntegration();

        $geofence = [
            'type' => 'circle',
            'center' => [
                'latitude' => 40.7128,
                'longitude' => -74.0060,
            ],
            'radius' => 1000, // 1 km
        ];

        // Point inside geofence
        $inside = $gpsIntegration->isWithinGeofence(
            40.7130,
            -74.0065,
            $geofence
        );
        $this->assertTrue($inside);

        // Point outside geofence
        $outside = $gpsIntegration->isWithinGeofence(
            40.8000,
            -74.1000,
            $geofence
        );
        $this->assertFalse($outside);
    }

    public function test_gps_geofence_polygon_check(): void
    {
        $gpsIntegration = app(GPSIntegration::class);

        // A tall, narrow (non-symmetric) rectangle: lat -6.30..-6.10, lng 106.80..106.85.
        // Swapping the lat/lng axes would give the wrong answer for these points.
        $geofence = [
            'type' => 'polygon',
            'coordinates' => [
                ['latitude' => -6.30, 'longitude' => 106.80],
                ['latitude' => -6.30, 'longitude' => 106.85],
                ['latitude' => -6.10, 'longitude' => 106.85],
                ['latitude' => -6.10, 'longitude' => 106.80],
            ],
        ];

        $this->assertTrue($gpsIntegration->isWithinGeofence(-6.20, 106.82, $geofence));
        $this->assertTrue($gpsIntegration->isWithinGeofence(-6.29, 106.81, $geofence));
        $this->assertFalse($gpsIntegration->isWithinGeofence(-6.20, 106.90, $geofence));
        $this->assertFalse($gpsIntegration->isWithinGeofence(-6.40, 106.82, $geofence));

        // Degenerate polygons never contain a point.
        $this->assertFalse($gpsIntegration->isWithinGeofence(-6.20, 106.82, [
            'type' => 'polygon',
            'coordinates' => [['latitude' => -6.20, 'longitude' => 106.82]],
        ]));
    }

    public function test_location_update_creates_movement_on_significant_change(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $this->actingAsProjectUser($project, 'project-admin');

        $integration = Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'gps',
        ]);

        $deviceType = \App\Models\DeviceType::factory()->create(['slug' => 'gps_tracker']);
        $gpsDevice = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'serial_number' => 'GPS-MOVE-1',
            'integration_id' => $integration->id,
            'device_type_id' => $deviceType->id,
        ]);
        $asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);
        DeviceBinding::create([
            'device_id' => $gpsDevice->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'bound_at' => now(),
        ]);

        $gpsService = app(GPSService::class);
        $fix = fn (float $lat, float $lng) => $gpsService->processLocationUpdate($integration, [
            'device_id' => 'GPS-MOVE-1',
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        // First fix: the asset gets a location and a movement.
        $fix(-6.2000, 106.8166);
        $this->assertSame(1, \App\Models\Movement::where('asset_id', $asset->id)->count());
        $first = \App\Models\AssetLocation::where('asset_id', $asset->id)->first();
        $this->assertNotNull($first->location_id);

        // Jitter under 10 m: no new movement.
        $fix(-6.20003, 106.81662);
        $this->assertSame(1, \App\Models\Movement::where('asset_id', $asset->id)->count());

        // ~5 km away: a new location and a movement from the first one.
        $fix(-6.2450, 106.8166);
        $movements = \App\Models\Movement::where('asset_id', $asset->id)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame('gps', $movements[1]->source);
        $this->assertSame($first->location_id, $movements[1]->from_location_id);
        $this->assertNotSame($first->location_id, $movements[1]->to_location_id);
        $this->assertSame(
            $movements[1]->to_location_id,
            \App\Models\AssetLocation::where('asset_id', $asset->id)->value('location_id')
        );
    }
}
