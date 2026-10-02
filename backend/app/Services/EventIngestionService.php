<?php

namespace App\Services;

use App\Events\AssetDetected;
use App\Events\StandardEventPublished;
use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Device;
use App\Models\EventLog;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts events in the standard contract (docs/architecture/events.md):
 *
 *   {"event": "...", "asset_id": "AST-...", "location_id": 1,
 *    "timestamp": "...", "source": "...", "metadata": {}}
 *
 * Core-owned events are applied through Core services (never by writing
 * tables directly); any other well-formed event is stored and forwarded to
 * webhooks unchanged. Everything is recorded in event_logs.
 */
class EventIngestionService
{
    /** Emitted by Core CRUD only — never accepted from outside. */
    public const RESERVED = ['asset.created', 'asset.updated', 'asset.deleted'];

    public function __construct(
        protected MovementService $movements
    ) {}

    /**
     * @param array{event: string, asset_id?: ?string, device_id?: ?string, location_id?: ?int,
     *              timestamp?: ?string, source?: ?string, metadata?: ?array} $data
     */
    public function ingest(array $data, int $organizationId, int $projectId): EventLog
    {
        $event = $data['event'];

        if (in_array($event, self::RESERVED, true)) {
            throw ApiException::invalid('Validation failed', [
                'event' => ["{$event} is emitted by the platform and cannot be published"],
            ]);
        }

        $occurredAt = isset($data['timestamp']) ? Carbon::parse($data['timestamp']) : now();
        $source = $data['source'] ?? 'api';
        $metadata = $data['metadata'] ?? [];
        [$asset, $device] = $this->resolveAsset($data, $projectId);

        return DB::transaction(function () use ($event, $data, $asset, $device, $occurredAt, $source, $metadata, $organizationId, $projectId) {
            $eventLog = EventLog::create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'asset_id' => $asset?->id,
                'device_id' => $device?->id,
                'event_type' => $event,
                'source' => $source,
                'payload' => [
                    'event' => $event,
                    'asset_id' => $asset?->system_id,
                    'project_id' => $projectId,
                    'location_id' => $data['location_id'] ?? null,
                    'timestamp' => $occurredAt->toIso8601String(),
                    'source' => $source,
                    'metadata' => $metadata,
                ],
                'occurred_at' => $occurredAt,
                'status' => 'pending',
            ]);

            match ($event) {
                'asset.location.updated' => $this->applyLocation($this->requireAsset($asset), $data, $occurredAt, $source, $metadata),
                'asset.detected' => $this->applyDetection($this->requireAsset($asset), $device, $source, $eventLog),
                'asset.status.changed' => $this->applyStatus($this->requireAsset($asset), $metadata),
                default => StandardEventPublished::dispatch($event, $organizationId, $projectId, $eventLog->payload),
            };

            $eventLog->update(['status' => 'processed', 'processed_at' => now()]);

            return $eventLog;
        });
    }

    /**
     * @return array{0: ?Asset, 1: ?Device}
     */
    protected function resolveAsset(array $data, int $projectId): array
    {
        $device = null;

        if (!empty($data['device_id'])) {
            $device = Device::where('project_id', $projectId)
                ->where('serial_number', $data['device_id'])
                ->first();

            if (!$device) {
                throw ApiException::invalid('Validation failed', ['device_id' => ['Unknown device in this project']]);
            }
        }

        if (!empty($data['asset_id'])) {
            $asset = Asset::where('project_id', $projectId)->where('system_id', $data['asset_id'])->first();

            if (!$asset) {
                throw ApiException::invalid('Validation failed', ['asset_id' => ['Unknown asset in this project']]);
            }

            return [$asset, $device];
        }

        return [$device?->boundAsset(), $device];
    }

    protected function requireAsset(?Asset $asset): Asset
    {
        if (!$asset) {
            throw ApiException::invalid('Validation failed', [
                'asset_id' => ['This event requires an asset_id, or a device_id bound to an asset'],
            ]);
        }

        return $asset;
    }

    protected function applyLocation(Asset $asset, array $data, Carbon $occurredAt, string $source, array $metadata): void
    {
        $location = Location::where('project_id', $asset->project_id)->find($data['location_id'] ?? null);

        if (!$location) {
            throw ApiException::invalid('Validation failed', ['location_id' => ['A location in this project is required']]);
        }

        $this->movements->recordMovement($asset, [
            'to_location_id' => $location->id,
            'source' => $source,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }

    protected function applyDetection(Asset $asset, ?Device $device, string $source, EventLog $eventLog): void
    {
        $asset->touchLastSeen();
        $device?->update(['status' => 'online', 'last_seen_at' => now()]);
        AssetDetected::dispatch($asset, $device, $source, $eventLog->id);
    }

    protected function applyStatus(Asset $asset, array $metadata): void
    {
        $status = $metadata['status'] ?? null;

        if (!is_string($status) || $status === '' || strlen($status) > 50) {
            throw ApiException::invalid('Validation failed', ['metadata.status' => ['metadata.status is required']]);
        }

        // Goes through the model so asset.status.changed + audit fire as usual.
        $asset->update(['status' => $status]);
    }
}
