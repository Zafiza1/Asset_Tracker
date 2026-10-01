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
use App\Integrations\GPS\GPSIntegration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class GPSService
{
    protected GPSIntegration $gpsIntegration;

    public function __construct(GPSIntegration $gpsIntegration)
    {
        $this->gpsIntegration = $gpsIntegration;
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

    /**
     * Process GPS location update event
     */
    public function processLocationUpdate(Integration $integration, array $locationData): EventLog
    {
        // Validate required fields
        if (empty($locationData['device_id'])) {
            throw new Exception('device_id is required');
        }

        if (empty($locationData['latitude']) || empty($locationData['longitude'])) {
            throw new Exception('latitude and longitude are required');
        }

        // Validate coordinates
        if (!$this->validateCoordinates($locationData['latitude'], $locationData['longitude'])) {
            throw new Exception('Invalid latitude or longitude values');
        }

        // Ingest the event
        $eventLog = $this->gpsIntegration->ingestLocationUpdate($integration, $locationData);

        // Resolve asset from device
        $asset = $this->gpsIntegration->resolveAssetFromDevice($locationData['device_id'], $integration);

        if ($asset) {
            // Update event log with asset reference
            $eventLog->update(['asset_id' => $asset->id]);

            // Update device last seen
            $device = Device::where('serial_number', $locationData['device_id'])
                ->where('project_id', $integration->project_id)
                ->first();

            if ($device) {
                $device->update([
                    'status' => 'online',
                    'last_seen_at' => now(),
                ]);
            }

            // Update asset location
            $this->updateAssetLocation($asset, $locationData, $integration);

            Log::info('GPS location update processed for asset', [
                'event_log_id' => $eventLog->id,
                'asset_id' => $asset->id,
                'device_id' => $locationData['device_id'],
                'latitude' => $locationData['latitude'],
                'longitude' => $locationData['longitude'],
            ]);
        } else {
            Log::warning('GPS location update could not resolve to asset', [
                'event_log_id' => $eventLog->id,
                'device_id' => $locationData['device_id'],
            ]);
        }

        return $eventLog;
    }

    /**
     * Update asset location and create movement record if needed
     */
    protected function updateAssetLocation(Asset $asset, array $locationData, Integration $integration): void
    {
        DB::beginTransaction();
        try {
            // Get or create location based on coordinates
            $location = $this->resolveOrCreateLocation($locationData, $integration);

            // Get current asset location (only one exists due to unique constraint)
            $currentAssetLocation = AssetLocation::where('asset_id', $asset->id)->first();

            // Check if location changed
            $locationChanged = !$currentAssetLocation ||
                $currentAssetLocation->location_id !== $location->id ||
                $this->significantLocationChange($currentAssetLocation, $locationData);

            if ($locationChanged) {
                // Delete previous location (unique constraint ensures only one)
                $fromLocationId = null;
                if ($currentAssetLocation) {
                    $fromLocationId = $currentAssetLocation->location_id;
                    $currentAssetLocation->delete();

                    // Create movement record
                    Movement::create([
                        'organization_id' => $asset->organization_id,
                        'project_id' => $asset->project_id,
                        'asset_id' => $asset->id,
                        'from_location_id' => $fromLocationId,
                        'to_location_id' => $location->id,
                        'occurred_at' => $locationData['timestamp'] ?? now(),
                        'source' => 'gps',
                        'metadata' => [
                            'integration_id' => $integration->id,
                            'device_id' => $locationData['device_id'],
                            'latitude' => $locationData['latitude'],
                            'longitude' => $locationData['longitude'],
                            'speed' => $locationData['speed'] ?? null,
                        ],
                    ]);
                }

                // Create new asset location
                AssetLocation::create([
                    'organization_id' => $asset->organization_id,
                    'project_id' => $asset->project_id,
                    'asset_id' => $asset->id,
                    'location_id' => $location->id,
                    'source' => 'gps',
                    'arrived_at' => $locationData['timestamp'] ?? now(),
                    'metadata' => [
                        'latitude' => $locationData['latitude'],
                        'longitude' => $locationData['longitude'],
                    ],
                ]);
            } else {
                // No location change, do nothing
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update asset location', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
        if (!$currentLocation->latitude || !$currentLocation->longitude) {
            return true;
        }

        $distance = $this->gpsIntegration->calculateDistance(
            $currentLocation->latitude,
            $currentLocation->longitude,
            $newData['latitude'],
            $newData['longitude']
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
     * Get current location of an asset via GPS
     */
    public function getAssetCurrentLocation(Asset $asset): ?array
    {
        $assetLocation = AssetLocation::where('asset_id', $asset->id)
            ->where('is_current', true)
            ->with('location')
            ->first();

        if (!$assetLocation) {
            return null;
        }

        return [
            'location_id' => $assetLocation->location_id,
            'location_name' => $assetLocation->location->name,
            'latitude' => $assetLocation->latitude,
            'longitude' => $assetLocation->longitude,
            'accuracy' => $assetLocation->accuracy,
            'recorded_at' => $assetLocation->recorded_at->toIso8601String(),
        ];
    }

    /**
     * Get location history for an asset
     */
    public function getAssetLocationHistory(Asset $asset, int $limit = 100): array
    {
        $movements = Movement::where('asset_id', $asset->id)
            ->with(['fromLocation', 'toLocation'])
            ->orderBy('timestamp', 'desc')
            ->limit($limit)
            ->get();

        return $movements->map(function ($movement) {
            return [
                'movement_id' => $movement->id,
                'from_location' => $movement->fromLocation ? [
                    'id' => $movement->fromLocation->id,
                    'name' => $movement->fromLocation->name,
                ] : null,
                'to_location' => $movement->toLocation ? [
                    'id' => $movement->toLocation->id,
                    'name' => $movement->toLocation->name,
                ] : null,
                'timestamp' => $movement->timestamp->toIso8601String(),
                'source' => $movement->source,
                'metadata' => $movement->metadata,
            ];
        })->toArray();
    }
}
