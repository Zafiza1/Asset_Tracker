# Integration Architecture

## Overview

The integration system enables connections to external hardware, software, and APIs through standardized contracts. The core platform remains technology-agnostic while supporting various integrations.

## Integration Philosophy

Core does not know implementation details of specific integrations. All integrations follow a standard contract and emit normalized events.

## Integration Architecture

```
Hardware
    ↓
Gateway / Provider
    ↓
Integration Adapter
    ↓
Normalized Event
    ↓
Event Bus
    ↓
Asset Tracker Core
```

## Integration Contract

All integrations must implement the `IntegrationContract`:

```php
interface IntegrationContract
{
    public function connect(): bool;
    public function disconnect(): bool;
    public function configure(array $config): void;
    public function testConnection(): bool;
    public function receive(): ?Event;
    public function normalize(array $rawData): array;
    public function healthCheck(): array;
}
```

## Database Schema

### integrations
```php
Schema::create('integrations', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('type'); // rfid, gps, ble, nfc, etc.
    $table->text('description');
    $table->json('capabilities')->nullable();
    $table->string('version');
    $table->string('status')->default('available');
    $table->json('metadata')->nullable();
    $table->timestamps();
});
```

### integration_configs
```php
Schema::create('integration_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('integration_id')->constrained()->onDelete('cascade');
    $table->string('status')->default('configured');
    $table->json('configuration');
    $table->json('credentials')->nullable(); // Encrypted
    $table->timestamp('last_health_check')->nullable();
    $table->json('health_status')->nullable();
    $table->timestamps();
    
    $table->unique(['project_id', 'integration_id']);
    $table->index(['organization_id', 'project_id']);
});
```

### devices
```php
Schema::create('devices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('device_type_id')->constrained()->onDelete('cascade');
    $table->foreignId('integration_config_id')->nullable()->constrained()->onDelete('set null');
    $table->string('system_id')->unique();
    $table->string('identifier'); // Hardware ID
    $table->string('name');
    $table->json('metadata')->nullable();
    $table->string('status')->default('active');
    $table->timestamp('last_seen')->nullable();
    $table->timestamps();
    
    $table->index(['organization_id', 'project_id']);
    $table->index(['integration_config_id']);
});
```

### device_types
```php
Schema::create('device_types', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('category'); // rfid_tag, gps_tracker, rfid_reader, etc.
    $table->json('capabilities')->nullable();
    $table->text('description')->nullable();
    $table->timestamps();
});
```

### device_bindings
```php
Schema::create('device_bindings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('device_id')->constrained()->onDelete('cascade');
    $table->foreignId('asset_id')->constrained()->onDelete('cascade');
    $table->timestamp('bound_at');
    $table->timestamp('unbound_at')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamps();
    
    $table->unique(['device_id', 'asset_id'], 'unique_active_binding');
    $table->index(['organization_id', 'project_id']);
    $table->index(['asset_id']);
});
```

## Event Contract

All integrations emit standardized events:

```json
{
  "event": "asset.location.updated",
  "asset_id": "AST-01J8X9",
  "project_id": "PRJ-001",
  "location_id": "LOC-001",
  "timestamp": "2026-09-23T10:00:00Z",
  "source": "gps",
  "metadata": {}
}
```

### Standard Events

```text
asset.created
asset.updated
asset.deleted
asset.location.updated
asset.status.changed
asset.detected
device.connected
device.disconnected
maintenance.created
maintenance.completed
integration.connected
integration.disconnected
```

## RFID Integration Example

### Event Flow

```
RFID Tag
    ↓
RFID Reader
    ↓
RFID Gateway
    ↓
RFID Integration
    ↓
Normalize Detection
    ↓
asset.detected event
    ↓
Resolve Device Binding
    ↓
Resolve Asset
    ↓
Update Asset Activity
    ↓
Audit/Event Log
```

### Implementation

```php
class RFIDIntegration implements IntegrationContract
{
    public function connect(): bool
    {
        // Connect to RFID reader/gateway
    }

    public function receive(): ?Event
    {
        // Receive RFID scan data
    }

    public function normalize(array $rawData): array
    {
        return [
            'event' => 'asset.detected',
            'device_id' => $rawData['tag_id'],
            'timestamp' => $rawData['timestamp'],
            'reader_id' => $rawData['reader_id'],
            'metadata' => [
                'rssi' => $rawData['rssi'] ?? null,
            ],
        ];
    }
}
```

## GPS Integration Example

### Event Flow

```
GPS Device
    ↓
GPS Provider/Gateway
    ↓
GPS Integration
    ↓
Normalize GPS Data
    ↓
asset.location.updated event
    ↓
Event Listener
    ↓
Update Asset Location
    ↓
Store Movement
    ↓
Audit Log
    ↓
Webhook
```

### Implementation

```php
class GPSIntegration implements IntegrationContract
{
    public function normalize(array $rawData): array
    {
        return [
            'event' => 'asset.location.updated',
            'device_id' => $rawData['device_id'],
            'latitude' => $rawData['lat'],
            'longitude' => $rawData['lng'],
            'timestamp' => $rawData['timestamp'],
            'metadata' => [
                'accuracy' => $rawData['accuracy'] ?? null,
                'altitude' => $rawData['altitude'] ?? null,
                'speed' => $rawData['speed'] ?? null,
            ],
        ];
    }
}
```

## Device Binding

### Binding Process

```php
class DeviceBindingService
{
    public function bind(Device $device, Asset $asset): DeviceBinding
    {
        // Check if device is already bound
        if ($this->isDeviceBound($device)) {
            throw new DeviceAlreadyBoundException();
        }

        return DeviceBinding::create([
            'organization_id' => $asset->organization_id,
            'project_id' => $asset->project_id,
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'bound_at' => now(),
        ]);
    }

    public function unbind(Device $device): void
    {
        $binding = DeviceBinding::where('device_id', $device->id)
                               ->whereNull('unbound_at')
                               ->firstOrFail();

        $binding->update(['unbound_at' => now()]);
    }

    public function rebind(Device $oldDevice, Device $newDevice, Asset $asset): void
    {
        $this->unbind($oldDevice);
        $this->bind($newDevice, $asset);
    }
}
```

### Multiple Integrations per Asset

One asset can have multiple integrations:

```
Asset AST-001
│
├── RFID
│   └── TAG-001
│
├── GPS
│   └── GPS-001
│
└── BLE
    └── BLE-001
```

## Integration Registry

```php
class IntegrationRegistry
{
    public function register(Integration $integration): void
    {
        // Register integration in database
    }

    public function get(string $slug): ?Integration
    {
        return Integration::where('slug', $slug)->first();
    }

    public function getByType(string $type): Collection
    {
        return Integration::where('type', $type)->get();
    }
}
```

## Integration Configuration

Dynamic configuration per project:

```php
// GPS Integration Configuration
$gpsConfig = [
    'provider' => 'example-gps',
    'endpoint' => 'https://api.example-gps.com',
    'api_key' => 'encrypted_key',
    'update_interval' => 60, // seconds
    'devices' => ['GPS-001', 'GPS-002'],
];

IntegrationConfig::create([
    'organization_id' => $project->organization_id,
    'project_id' => $project->id,
    'integration_id' => $gpsIntegration->id,
    'configuration' => $gpsConfig,
    'credentials' => [
        'api_key' => encrypt('actual_api_key'),
    ],
]);
```

## Event Processing

### Event Listener

```php
class AssetLocationUpdatedListener
{
    public function handle(AssetLocationUpdated $event): void
    {
        // 1. Update asset location
        $asset = Asset::find($event->assetId);
        $asset->updateLocation($event->locationId);

        // 2. Store movement
        Movement::create([
            'asset_id' => $event->assetId,
            'from_location_id' => $asset->previous_location_id,
            'to_location_id' => $event->locationId,
            'timestamp' => $event->timestamp,
            'source' => $event->source,
        ]);

        // 3. Audit log
        ActivityLog::create([
            'organization_id' => $asset->organization_id,
            'project_id' => $asset->project_id,
            'action' => 'asset.location.updated',
            'resource_id' => $asset->id,
        ]);

        // 4. Trigger webhooks
        WebhookService::dispatch($event);
    }
}
```

## Error Handling

Integration failures should not break the core:

```php
try {
    $gpsIntegration->receive();
} catch (IntegrationException $e) {
    // Mark integration as degraded
    IntegrationConfig::where('id', $config->id)
                     ->update([
                         'health_status' => [
                             'status' => 'degraded',
                             'error' => $e->getMessage(),
                             'timestamp' => now(),
                         ],
                     ]);

    // Log error
    Log::error('GPS integration error', [
        'integration' => 'gps',
        'error' => $e->getMessage(),
    ]);

    // Core continues to function
}
```

## Health Monitoring

```php
class HealthCheckService
{
    public function checkAll(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'integrations' => $this->checkIntegrations(),
        ];
    }

    public function checkIntegrations(): array
    {
        $configs = IntegrationConfig::all();
        $results = [];

        foreach ($configs as $config) {
            $integration = $config->integration;
            $adapter = $this->getAdapter($integration);

            $results[$integration->slug] = [
                'status' => $adapter->healthCheck()['status'],
                'last_check' => now(),
            ];
        }

        return $results;
    }
}
```

## Security

1. **Credential Encryption**: Integration credentials encrypted at rest
2. **API Key Management**: Secure storage and rotation of API keys
3. **Tenant Isolation**: Integration configurations scoped to organization/project
4. **Input Validation**: All integration data validated
5. **Rate Limiting**: Integration endpoints rate-limited
6. **Webhook Security**: Signature verification for webhooks

## Testing

### Integration Test

```php
public function test_gps_integration_normalization()
{
    $integration = new GPSIntegration();
    $rawData = [
        'device_id' => 'GPS-001',
        'lat' => 40.7128,
        'lng' => -74.0060,
        'timestamp' => '2026-09-23T10:00:00Z',
    ];

    $normalized = $integration->normalize($rawData);

    $this->assertEquals('asset.location.updated', $normalized['event']);
    $this->assertEquals('GPS-001', $normalized['device_id']);
    $this->assertEquals(40.7128, $normalized['latitude']);
    $this->assertEquals(-74.0060, $normalized['longitude']);
}
```

### Device Binding Test

```php
public function test_device_binding()
{
    $device = Device::factory()->create();
    $asset = Asset::factory()->create();

    $binding = $this->service->bind($device, $asset);

    $this->assertDatabaseHas('device_bindings', [
        'device_id' => $device->id,
        'asset_id' => $asset->id,
        'unbound_at' => null,
    ]);
}

public function test_device_rebinding()
{
    $oldDevice = Device::factory()->create();
    $newDevice = Device::factory()->create();
    $asset = Asset::factory()->create();

    $this->service->bind($oldDevice, $asset);
    $this->service->rebind($oldDevice, $newDevice, $asset);

    $this->assertNull($oldDevice->fresh()->activeBinding);
    $this->assertNotNull($newDevice->fresh()->activeBinding);
}
```

## Future Integrations

The architecture supports adding new integrations without core changes:

- LoRaWAN
- NFC
- IoT Platforms
- Barcode/QR
- External APIs
- ERP Systems
- WMS/TMS
- Custom Integrations

## Summary

The integration system provides:
- Standardized contracts
- Event-driven architecture
- Device binding mechanism
- Multi-integration support
- Health monitoring
- Error isolation
- Tenant isolation
- Extensibility
