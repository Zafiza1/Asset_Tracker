# Event-Driven Architecture

## Overview

Asset Tracker PaaS uses an event-driven architecture to enable loose coupling between components, support asynchronous processing, and facilitate extensibility.

## Event Philosophy

Events represent state changes in the system. They are:
- Immutable
- Timestamped
- Typed
- Tenant-scoped
- Processed asynchronously

## Event Flow MVP

For the MVP, we use Laravel Events + Queue:

```
Application
    ↓
Laravel Event
    ↓
Queue
    ↓
Worker
    ↓
Integration / Webhook / Processing
```

## Future Event Bus

As the platform scales, we can upgrade to:

```
Application
    ↓
Event Bus
    ↓
RabbitMQ / NATS / Kafka
```

## Standard Event Contract

All events follow a consistent format:

```json
{
  "event": "asset.location.updated",
  "asset_id": "AST-01J8X9",
  "project_id": "PRJ-001",
  "organization_id": "ORG-001",
  "timestamp": "2026-09-23T10:00:00Z",
  "source": "gps",
  "metadata": {}
}
```

## Event Categories

### Asset Events
```text
asset.created
asset.updated
asset.deleted
asset.status.changed
asset.detected
```

### Location Events
```text
asset.location.updated
location.created
location.updated
location.deleted
```

### Movement Events
```text
movement.created
movement.completed
```

### Device Events
```text
device.connected
device.disconnected
device.bound
device.unbound
device.status.changed
```

### Integration Events
```text
integration.connected
integration.disconnected
integration.health.changed
```

### Maintenance Events
```text
maintenance.created
maintenance.completed
maintenance.scheduled
maintenance.overdue
```

### Inspection Events
```text
inspection.created
inspection.completed
inspection.failed
```

### User Events
```text
user.created
user.updated
user.deleted
user.role.changed
```

### Project Events
```text
project.created
project.updated
project.deleted
project.module.installed
project.module.configured
project.module.enabled
project.module.disabled
project.module.upgraded
project.module.uninstalled
```

Module events are dispatched as `App\Events\ModuleLifecycleChanged` after the
lifecycle transaction commits; `payload()` returns the standard contract
(`event`, `organization_id`, `project_id`, `module`, `version`, `status`,
`previous_status`, `user_id`, `timestamp`, `metadata`). `project.module.upgraded`
carries `from_version`/`to_version` in `metadata`.

## Database Schema

### events
```php
Schema::create('events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->nullable()->constrained()->onDelete('set null');
    $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
    $table->string('event_type');
    $table->string('entity_type')->nullable();
    $table->unsignedBigInteger('entity_id')->nullable();
    $table->json('payload');
    $table->timestamp('occurred_at');
    $table->timestamp('processed_at')->nullable();
    $table->string('status')->default('pending');
    $table->integer('retry_count')->default(0);
    $table->timestamp('next_retry_at')->nullable();
    $table->json('error_log')->nullable();
    $table->timestamps();
    
    $table->index(['organization_id', 'project_id']);
    $table->index(['event_type', 'status']);
    $table->index(['occurred_at']);
    $table->index(['entity_type', 'entity_id']);
});
```

### event_logs
```php
Schema::create('event_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->nullable()->constrained()->onDelete('set null');
    $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
    $table->string('event_type');
    $table->foreignId('asset_id')->nullable()->constrained()->onDelete('set null');
    $table->foreignId('device_id')->nullable()->constrained()->onDelete('set null');
    $table->string('source');
    $table->json('payload');
    $table->timestamp('occurred_at');
    $table->timestamp('processed_at')->nullable();
    $table->string('status')->default('pending');
    $table->json('metadata')->nullable();
    $table->timestamps();
    
    $table->index(['organization_id', 'project_id']);
    $table->index(['event_type']);
    $table->index(['asset_id']);
    $table->index(['device_id']);
    $table->index(['occurred_at']);
});
```

## Event Definition

### Base Event Class

```php
abstract class BaseEvent implements ShouldDispatchAfterCommit
{
    public string $eventType;
    public ?string $organizationId;
    public ?string $projectId;
    public Carbon $timestamp;
    public ?string $source;
    public array $metadata = [];

    public function __construct(array $data)
    {
        $this->eventType = $data['event_type'];
        $this->organizationId = $data['organization_id'] ?? null;
        $this->projectId = $data['project_id'] ?? null;
        $this->timestamp = $data['timestamp'] ?? now();
        $this->source = $data['source'] ?? null;
        $this->metadata = $data['metadata'] ?? [];
    }

    public function broadcastOn(): array
    {
        return [];
    }
}
```

### Asset Events

```php
class AssetCreated extends BaseEvent
{
    public function __construct(
        public string $assetId,
        array $data = []
    ) {
        parent::__construct(array_merge($data, [
            'event_type' => 'asset.created',
            'entity_type' => 'Asset',
            'entity_id' => $assetId,
        ]));
    }
}

class AssetLocationUpdated extends BaseEvent
{
    public function __construct(
        public string $assetId,
        public ?string $locationId,
        array $data = []
    ) {
        parent::__construct(array_merge($data, [
            'event_type' => 'asset.location.updated',
            'entity_type' => 'Asset',
            'entity_id' => $assetId,
        ]));
    }
}

class AssetDetected extends BaseEvent
{
    public function __construct(
        public string $assetId,
        public string $deviceId,
        array $data = []
    ) {
        parent::__construct(array_merge($data, [
            'event_type' => 'asset.detected',
            'entity_type' => 'Asset',
            'entity_id' => $assetId,
        ]));
    }
}
```

## Event Listeners

### Location Update Listener

```php
class AssetLocationUpdatedListener
{
    public function handle(AssetLocationUpdated $event): void
    {
        $asset = Asset::find($event->assetId);
        
        if (!$asset) {
            Log::warning('Asset not found for location update', [
                'asset_id' => $event->assetId,
            ]);
            return;
        }

        // Store previous location
        $previousLocationId = $asset->location_id;

        // Update asset location
        $asset->update([
            'location_id' => $event->locationId,
        ]);

        // Create movement record
        if ($previousLocationId !== $event->locationId) {
            Movement::create([
                'organization_id' => $asset->organization_id,
                'project_id' => $asset->project_id,
                'asset_id' => $asset->id,
                'from_location_id' => $previousLocationId,
                'to_location_id' => $event->locationId,
                'timestamp' => $event->timestamp,
                'source' => $event->source,
                'metadata' => $event->metadata,
            ]);
        }

        // Log activity
        ActivityLog::create([
            'organization_id' => $asset->organization_id,
            'project_id' => $asset->project_id,
            'user_id' => auth()->id(),
            'action' => 'asset.location.updated',
            'resource_type' => 'Asset',
            'resource_id' => $asset->id,
            'metadata' => [
                'from_location' => $previousLocationId,
                'to_location' => $event->locationId,
                'source' => $event->source,
            ],
        ]);

        // Dispatch webhooks
        WebhookService::dispatchForEvent($event);
    }
}
```

### Asset Detection Listener

```php
class AssetDetectedListener
{
    public function handle(AssetDetected $event): void
    {
        // Resolve device binding
        $binding = DeviceBinding::where('device_id', $event->deviceId)
                               ->whereNull('unbound_at')
                               ->first();

        if (!$binding) {
            Log::info('Device not bound to any asset', [
                'device_id' => $event->deviceId,
            ]);
            return;
        }

        // Update asset last detected
        $asset = $binding->asset;
        $asset->update([
            'last_detected_at' => $event->timestamp,
        ]);

        // Update device last seen
        $device = $binding->device;
        $device->update([
            'last_seen' => $event->timestamp,
        ]);

        // Log detection
        EventLog::create([
            'organization_id' => $asset->organization_id,
            'project_id' => $asset->project_id,
            'event_type' => 'asset.detected',
            'asset_id' => $asset->id,
            'device_id' => $event->deviceId,
            'source' => $event->source,
            'payload' => $event->metadata,
            'occurred_at' => $event->timestamp,
            'processed_at' => now(),
            'status' => 'processed',
        ]);
    }
}
```

## Event Registration

### EventServiceProvider

```php
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        AssetCreated::class => [
            AssetCreatedListener::class,
        ],
        AssetLocationUpdated::class => [
            AssetLocationUpdatedListener::class,
        ],
        AssetDetected::class => [
            AssetDetectedListener::class,
        ],
        DeviceConnected::class => [
            DeviceConnectedListener::class,
        ],
        IntegrationConnected::class => [
            IntegrationConnectedListener::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
```

## Event Dispatching

### Synchronous Dispatch

```php
event(new AssetCreated($asset->id, [
    'organization_id' => $asset->organization_id,
    'project_id' => $asset->project_id,
    'source' => 'api',
]));
```

### Asynchronous Dispatch (Queue)

```php
AssetCreated::dispatch($asset->id, [
    'organization_id' => $asset->organization_id,
    'project_id' => $asset->project_id,
    'source' => 'api',
]);
```

### Dispatch After Commit

```php
AssetCreated::dispatchIf($condition, $asset->id, $data);
AssetCreated::dispatchUnless($condition, $asset->id, $data);
```

## Event Queue Configuration

### config/queue.php

```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],

'jobs' => [
    'events' => [
        'connection' => 'redis',
        'queue' => 'events',
        'balance' => 'simple',
        'tries' => 3,
    ],
],
```

## Event Storage

### Event Store

```php
class EventStore
{
    public function store(BaseEvent $event): Event
    {
        return Event::create([
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'event_type' => $event->eventType,
            'entity_type' => $event->entityType ?? null,
            'entity_id' => $event->entityId ?? null,
            'payload' => $event->metadata,
            'occurred_at' => $event->timestamp,
            'status' => 'pending',
        ]);
    }

    public function markProcessed(Event $event): void
    {
        $event->update([
            'processed_at' => now(),
            'status' => 'processed',
        ]);
    }

    public function markFailed(Event $event, string $error): void
    {
        $event->update([
            'status' => 'failed',
            'error_log' => [
                'error' => $error,
                'timestamp' => now(),
            ],
            'retry_count' => $event->retry_count + 1,
            'next_retry_at' => now()->addMinutes(pow(2, $event->retry_count)),
        ]);
    }
}
```

## Event Replay

For debugging and recovery:

```php
class EventReplayService
{
    public function replay(Event $event): void
    {
        $event->update([
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        event($this->reconstructEvent($event));
    }

    public function replayByDateRange(Carbon $from, Carbon $to): void
    {
        Event::whereBetween('occurred_at', [$from, $to])
              ->where('status', 'failed')
              ->get()
              ->each(fn ($event) => $this->replay($event));
    }
}
```

## Webhook Integration

### Webhook Dispatcher

```php
class WebhookService
{
    public function dispatchForEvent(BaseEvent $event): void
    {
        $webhooks = Webhook::where('organization_id', $event->organizationId)
                          ->where('project_id', $event->projectId)
                          ->where('active', true)
                          ->whereJsonContains('events', $event->eventType)
                          ->get();

        foreach ($webhooks as $webhook) {
            WebhookDeliveryJob::dispatch($webhook, $event);
        }
    }
}
```

## Event Testing

### Event Test

```php
public function test_asset_location_updated_event()
{
    Event::fake([
        AssetLocationUpdated::class,
    ]);

    $asset = Asset::factory()->create();
    $location = Location::factory()->create();

    $asset->update(['location_id' => $location->id]);

    Event::assertDispatched(AssetLocationUpdated::class, function ($event) use ($asset, $location) {
        return $event->assetId === $asset->id 
            && $event->locationId === $location->id;
    });
}

public function test_asset_detection_listener()
{
    $asset = Asset::factory()->create();
    $device = Device::factory()->create();
    $binding = DeviceBinding::factory()->create([
        'asset_id' => $asset->id,
        'device_id' => $device->id,
    ]);

    event(new AssetDetected($asset->id, $device->id));

    $this->assertDatabaseHas('assets', [
        'id' => $asset->id,
        'last_detected_at' => now(),
    ]);
}
```

## Performance Considerations

1. **Queue Configuration**: Use separate queues for different event types
2. **Batch Processing**: Batch similar events for efficiency
3. **Indexing**: Proper indexes on event tables
4. **Archiving**: Archive old events to separate storage
5. **Monitoring**: Monitor queue depth and processing time

## Security

1. **Tenant Scoping**: Events scoped to organization/project
2. **Authorization**: Event access controlled by permissions
3. **Validation**: Event payloads validated
4. **Audit Logging**: Event processing logged
5. **Rate Limiting**: Event generation rate-limited

## Monitoring

### Health Checks

```php
class EventHealthCheck
{
    public function check(): array
    {
        return [
            'pending_events' => Event::where('status', 'pending')->count(),
            'failed_events' => Event::where('status', 'failed')->count(),
            'oldest_pending' => Event::where('status', 'pending')
                                   ->orderBy('occurred_at')
                                   ->first()?->occurred_at,
            'queue_size' => Queue::size('events'),
        ];
    }
}
```

## Summary

The event system provides:
- Loose coupling between components
- Asynchronous processing
- Extensibility through listeners
- Audit trail through event logs
- Webhook integration
- Event replay capability
- Tenant isolation
- Performance optimization
