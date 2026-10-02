<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Integration;
use App\Models\EventLog;
use App\Models\Asset;
use App\Models\Location;
use App\Models\AssetLocation;
use App\Models\Movement;
use Illuminate\Support\Carbon;
use App\Integrations\Contracts\IngestsReadings;
use App\Integrations\GPS\GPSIntegration;
use Illuminate\Support\Facades\Log;
use Exception;

class GPSService implements IngestsReadings
{
    public function __construct(
        protected GPSIntegration $gpsIntegration,
        protected MovementService $movements
    ) {
    }

    /**
     * Register a GPS tracker as a device
     */
    public function registerTracker(array $data, Integration $integration): Device
    {
        // Get or create GPS tracker device type
        $deviceType = DeviceType::firstOrCreate(
            ['slug' => 'gps_tracker'],
            [
                'name' => 'GPS Tracker',
                'description' => 'GPS tracker device for asset location tracking',
                'capabilities' => ['location_tracking', 'geofencing', 'speed_monitoring'],
                'metadata' => [
                    'technology' => 'gps',
                    'update_interval' => 'configurable',
                ],
            ]
        );

        return Device::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'device_type_id' => $deviceType->id,
            'integration_id' => $integration->id,
            'serial_number' => $data['device_id'],
            'name' => $data['name'] ?? "GPS Tracker {$data['device_id']}",
            'status' => 'online',
            'metadata' => [
                'tracker_type' => $data['tracker_type'] ?? 'standalone',
                'battery_type' => $data['battery_type'] ?? null,
                'firmware_version' => $data['firmware_version'] ?? null,
                'update_interval' => $data['update_interval'] ?? 60,
            ],
        ]);
    }

    public function ingest(Integration $integration, array $reading): EventLog
    {
        return $this->processLocationUpdate($integration, $reading);
    }

    /**
     * Process GPS location update event
     */
    public function processLocationUpdate(Integration $integration, array $locationData): EventLog
    {
        // Validate required fields
        if (empty($locationData['device_id'])) {
            throw new Exception('device_id is required');
        }

        // 0 is a valid coordinate (equator / prime meridian) — only reject missing values.
        if (!isset($locationData['latitude'], $locationData['longitude'])
            || !is_numeric($locationData['latitude']) || !is_numeric($locationData['longitude'])) {
            throw new Exception('latitude and longitude are required');
        }

        // Validate coordinates
        if (!$this->validateCoordinates((float) $locationData['latitude'], (float) $locationData['longitude'])) {
            throw new Exception('Invalid latitude or longitude values');
        }

        // Ingest the event
        $eventLog = $this->gpsIntegration->ingestLocationUpdate($integration, $locationData);

        // Resolve device → active binding → asset
        $device = Device::where('serial_number', $locationData['device_id'])
            ->where('project_id', $integration->project_id)
            ->first();
        $asset = $device?->boundAsset();

        $device?->update(['status' => 'online', 'last_seen_at' => now()]);

        if ($asset) {
            $asset->touchLastSeen();
            $this->updateAssetLocation($asset, $locationData, $integration);
        } else {
            Log::warning('GPS location update could not resolve to asset', [
                'event_log_id' => $eventLog->id,
                'device_id' => $locationData['device_id'],
            ]);
        }

        // An unresolved update stays processed with a null asset_id.
        $eventLog->update([
            'device_id' => $device?->id,
            'asset_id' => $asset?->id,
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        return $eventLog;
    }

    /**
     * Move the asset to the location nearest the GPS fix. Goes through
     * MovementService so GPS updates produce the same movement history,
     * current-location pointer and asset.location.updated event as any other
     * source — the integration never writes Core tables directly.
     */
    protected function updateAssetLocation(Asset $asset, array $locationData, Integration $integration): void
    {
        $location = $this->resolveOrCreateLocation($locationData, $integration);
        $current = AssetLocation::where('asset_id', $asset->id)->first();

        $locationChanged = !$current
            || $current->location_id !== $location->id
            || $this->significantLocationChange($current, $locationData);

        if (!$locationChanged) {
            return;
        }

        $this->movements->recordMovement($asset, [
            'to_location_id' => $location->id,
            'source' => 'gps',
            'occurred_at' => $locationData['timestamp'] ?? now(),
            'metadata' => [
                'integration_id' => $integration->id,
                'device_id' => $locationData['device_id'],
                'latitude' => (float) $locationData['latitude'],
                'longitude' => (float) $locationData['longitude'],
                'speed' => $locationData['speed'] ?? null,
                'accuracy' => $locationData['accuracy'] ?? null,
            ],
        ]);
    }

    /**
     * Resolve or create location based on GPS coordinates
     */
    protected function resolveOrCreateLocation(array $locationData, Integration $integration): Location
    {
        // First try to find existing location within proximity
        // Using bounding box for MVP (simpler than PostGIS)
        $proximityMeters = 50; // 50 meters proximity
        $latDelta = $proximityMeters / 111320; // ~111320 meters per degree latitude
        $lngDelta = $proximityMeters / (111320 * cos(deg2rad($locationData['latitude'])));

        $existingLocation = Location::where('project_id', $integration->project_id)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [
                $locationData['latitude'] - $latDelta,
                $locationData['latitude'] + $latDelta,
            ])
            ->whereBetween('longitude', [
                $locationData['longitude'] - $lngDelta,
                $locationData['longitude'] + $lngDelta,
            ])
            ->first();

        if ($existingLocation) {
            return $existingLocation;
        }

        // Create new location
        return Location::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'name' => $locationData['address'] ?? "GPS Location",
            'type' => 'outdoor',
            'latitude' => $locationData['latitude'],
            'longitude' => $locationData['longitude'],
            'metadata' => [
                'source' => 'gps',
                'accuracy' => $locationData['accuracy'] ?? null,
                'altitude' => $locationData['altitude'] ?? null,
            ],
        ]);
    }

    /**
     * Check if location change is significant (more than 10 meters)
     */
    protected function significantLocationChange(AssetLocation $currentLocation, array $newData): bool
    {
        // The last GPS fix is stored on the pointer's metadata.
        $lat = $currentLocation->metadata['latitude'] ?? null;
        $lng = $currentLocation->metadata['longitude'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return true;
        }

        $distance = $this->gpsIntegration->calculateDistance(
            (float) $lat,
            (float) $lng,
            (float) $newData['latitude'],
            (float) $newData['longitude']
        );

        return $distance > 10; // 10 meters threshold
    }

    /**
     * Validate GPS device ID format
     */
    public function validateDeviceId(string $deviceId): bool
    {
        // Basic validation: alphanumeric with hyphens/underscores
        return preg_match('/^[A-Z0-9\-_]{4,32}$/i', $deviceId) === 1;
    }

    /**
     * Validate latitude and longitude
     */
    public function validateCoordinates(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Get GPS devices for an integration
     */
    public function getGPSDevices(Integration $integration)
    {
        return Device::where('integration_id', $integration->id)
            ->where('project_id', $integration->project_id)
            ->whereHas('deviceType', fn($q) => $q->where('slug', 'gps_tracker'))
            ->with('deviceType', 'currentBinding.asset')
            ->get();
    }

    /**
     * Bulk register GPS trackers
     */
    public function bulkRegisterTrackers(array $trackers, Integration $integration): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($trackers as $trackerData) {
            try {
                if (!$this->validateDeviceId($trackerData['device_id'])) {
                    throw new Exception("Invalid device ID format: {$trackerData['device_id']}");
                }

                $this->registerTracker($trackerData, $integration);
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'device_id' => $trackerData['device_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk ingest GPS location updates
     */
    public function bulkIngestLocationUpdates(array $updates, Integration $integration): array
    {
        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($updates as $updateData) {
            try {
                $this->processLocationUpdate($integration, $updateData);
                $results['processed']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'device_id' => $updateData['device_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get GPS location update statistics
     */
    public function getLocationUpdateStats(Integration $integration, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $totalUpdates = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.location.updated')
            ->where('occurred_at', '>=', $since)
            ->count();

        $uniqueDevices = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.location.updated')
            ->where('occurred_at', '>=', $since)
            ->distinct()
            ->pluck('payload->device_serial')
            ->count();

        $resolvedAssets = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.location.updated')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('asset_id')
            ->distinct()
            ->count('asset_id');

        $movementsCreated = Movement::where('project_id', $integration->project_id)
            ->where('source', 'gps')
            ->where('occurred_at', '>=', $since)
            ->count();

        return [
            'total_updates' => $totalUpdates,
            'unique_devices' => $uniqueDevices,
            'resolved_assets' => $resolvedAssets,
            'movements_created' => $movementsCreated,
            'resolution_rate' => $totalUpdates > 0 ? round(($resolvedAssets / $totalUpdates) * 100, 2) : 0,
            'period_hours' => $hours,
        ];
    }

    /**
     * Current location of an asset (from the current-location pointer).
     */
    public function getAssetCurrentLocation(Asset $asset): ?array
    {
        $assetLocation = AssetLocation::where('asset_id', $asset->id)
            ->with('location')
            ->first();

        if (!$assetLocation || !$assetLocation->location) {
            return null;
        }

        $metadata = $assetLocation->metadata ?? [];

        return [
            'location_id' => $assetLocation->location_id,
            'location_name' => $assetLocation->location->name,
            'latitude' => $metadata['latitude'] ?? $assetLocation->location->latitude,
            'longitude' => $metadata['longitude'] ?? $assetLocation->location->longitude,
            'accuracy' => $metadata['accuracy'] ?? null,
            'source' => $assetLocation->source,
            'recorded_at' => $assetLocation->arrived_at?->toIso8601String(),
        ];
    }

    /**
     * Movement history for an asset, newest first.
     */
    public function getAssetLocationHistory(Asset $asset, int $limit = 100): array
    {
        $movements = Movement::where('asset_id', $asset->id)
            ->with(['fromLocation', 'toLocation'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return $movements->map(fn (Movement $movement) => [
            'movement_id' => $movement->id,
            'from_location' => $movement->fromLocation
                ? ['id' => $movement->fromLocation->id, 'name' => $movement->fromLocation->name]
                : null,
            'to_location' => $movement->toLocation
                ? ['id' => $movement->toLocation->id, 'name' => $movement->toLocation->name]
                : null,
            'timestamp' => Carbon::parse($movement->occurred_at)->toIso8601String(),
            'source' => $movement->source,
            'metadata' => $movement->metadata,
        ])->toArray();
    }
}
