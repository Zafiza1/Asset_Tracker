<?php

namespace App\Integrations\GPS;

use App\Integrations\Contracts\IntegrationContract;
use App\Models\Integration;
use App\Models\Device;
use App\Models\EventLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GPSIntegration implements IntegrationContract
{
    protected array $config = [];

    public function connect(Integration $integration): bool
    {
        $this->config = $this->loadConfig($integration);

        try {
            // Test connection to GPS provider/gateway
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                    'Content-Type' => 'application/json',
                ])
                ->get($this->config['endpoint'] ?? '');

            return $response->successful();
        } catch (Exception $e) {
            Log::error('GPS connection failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function disconnect(Integration $integration): bool
    {
        // For GPS, disconnection is mostly logical
        // We clear any active connections in the provider
        $this->config = $this->loadConfig($integration);

        try {
            if (!empty($this->config['endpoint'])) {
                Http::timeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                    ])
                    ->post($this->config['endpoint'] . '/disconnect');
            }

            return true;
        } catch (Exception $e) {
            Log::error('GPS disconnection failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function configure(Integration $integration, array $config): bool
    {
        // Configuration is stored in integration_configs table
        // This method validates and applies configuration
        $validation = $this->validateConfig($config);

        if (!$validation['valid']) {
            Log::error('GPS configuration validation failed', [
                'integration_id' => $integration->id,
                'errors' => $validation['errors'],
            ]);
            return false;
        }

        // Config is stored by IntegrationService
        return true;
    }

    public function testConnection(Integration $integration): array
    {
        $this->config = $this->loadConfig($integration);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                    'Content-Type' => 'application/json',
                ])
                ->get($this->config['endpoint'] . '/health');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'GPS connection successful',
                    'details' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => 'GPS connection failed: ' . $response->body(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'GPS connection error: ' . $e->getMessage(),
            ];
        }
    }

    public function receive(Integration $integration): array
    {
        $this->config = $this->loadConfig($integration);

        try {
            // Poll for GPS location events from the provider
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                ])
                ->get($this->config['endpoint'] . '/locations', [
                    'since' => $this->config['last_poll_at'] ?? now()->subMinutes(5)->toIso8601String(),
                    'device_id' => $this->config['device_id'] ?? null,
                ]);

            if ($response->successful()) {
                $events = $response->json('locations', []);
                return $events;
            }

            return [];
        } catch (Exception $e) {
            Log::error('GPS receive failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function normalize(array $rawData): array
    {
        // Normalize GPS location event to standard format
        // Expected raw format: { device_id, latitude, longitude, timestamp, speed, heading, altitude }

        return [
            'event_type' => 'asset.location.updated',
            'payload' => [
                'device_serial' => $rawData['device_id'] ?? null,
                'latitude' => $rawData['latitude'] ?? null,
                'longitude' => $rawData['longitude'] ?? null,
                'timestamp' => $rawData['timestamp'] ?? now()->toIso8601String(),
                'speed' => $rawData['speed'] ?? null,
                'heading' => $rawData['heading'] ?? null,
                'altitude' => $rawData['altitude'] ?? null,
                'accuracy' => $rawData['accuracy'] ?? null,
                'address' => $rawData['address'] ?? null,
            ],
            'metadata' => [
                'source_type' => 'gps',
                'provider' => $rawData['provider'] ?? 'unknown',
                'fix_type' => $rawData['fix_type'] ?? 'unknown',
                'satellite_count' => $rawData['satellite_count'] ?? null,
            ],
        ];
    }

    public function healthCheck(Integration $integration): array
    {
        $result = $this->testConnection($integration);

        $status = $result['success'] ? 'healthy' : 'unhealthy';

        return [
            'status' => $status,
            'message' => $result['message'],
            'details' => $result['details'] ?? [],
        ];
    }

    public function getType(): string
    {
        return 'gps';
    }

    public function getName(): string
    {
        return 'GPS Integration';
    }

    public function getSupportedEventTypes(): array
    {
        return [
            'asset.location.updated',
            'asset.moving',
            'asset.stationary',
            'device.connected',
            'device.disconnected',
            'geofence.entered',
            'geofence.exited',
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['endpoint'])) {
            $errors['endpoint'] = 'Endpoint is required';
        }

        if (empty($config['api_key'])) {
            $errors['api_key'] = 'API key is required';
        }

        if (!empty($config['endpoint']) && !filter_var($config['endpoint'], FILTER_VALIDATE_URL)) {
            $errors['endpoint'] = 'Endpoint must be a valid URL';
        }

        if (!empty($config['poll_interval']) && !is_numeric($config['poll_interval'])) {
            $errors['poll_interval'] = 'Poll interval must be numeric';
        }

        if (!empty($config['poll_interval']) && $config['poll_interval'] < 10) {
            $errors['poll_interval'] = 'Poll interval must be at least 10 seconds';
        }

        if (!empty($config['location_update_interval']) && !is_numeric($config['location_update_interval'])) {
            $errors['location_update_interval'] = 'Location update interval must be numeric';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function loadConfig(Integration $integration): array
    {
        $configs = $integration->configs->pluck('value', 'key')->toArray();
        $this->config = $configs;
        return $configs;
    }

    /**
     * Ingest a single GPS location update event
     * This is called when a GPS device sends a real-time location update
     */
    public function ingestLocationUpdate(Integration $integration, array $locationData): EventLog
    {
        $normalized = $this->normalize($locationData);

        return EventLog::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'integration_id' => $integration->id,
            'event_type' => $normalized['event_type'],
            'source' => 'gps',
            'payload' => $normalized['payload'],
            'metadata' => array_merge($normalized['metadata'], [
                'integration_id' => $integration->id,
                'raw_data' => $locationData,
            ]),
            'occurred_at' => $normalized['payload']['timestamp'],
            'status' => 'pending',
        ]);
    }

    /**
     * Resolve asset from GPS device via device binding
     */
    public function resolveAssetFromDevice(string $deviceSerial, Integration $integration): ?\App\Models\Asset
    {
        // Find device by serial number
        $device = Device::where('serial_number', $deviceSerial)
            ->where('project_id', $integration->project_id)
            ->first();

        if (!$device) {
            return null;
        }

        // Get currently bound asset
        return $device->boundAsset();
    }

    /**
     * Check if location is within geofence
     */
    public function isWithinGeofence(float $lat, float $lng, array $geofence): bool
    {
        // Simple circle geofence check
        if (isset($geofence['type']) && $geofence['type'] === 'circle') {
            $centerLat = $geofence['center']['latitude'];
            $centerLng = $geofence['center']['longitude'];
            $radius = $geofence['radius'];

            $distance = $this->calculateDistance($lat, $lng, $centerLat, $centerLng);
            return $distance <= $radius;
        }

        // Polygon geofence check (ray casting algorithm)
        if (isset($geofence['type']) && $geofence['type'] === 'polygon') {
            return $this->isPointInPolygon($lat, $lng, $geofence['coordinates']);
        }

        return false;
    }

    /**
     * Calculate distance between two coordinates in meters
     * Uses Haversine formula
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Check if point is inside polygon using ray casting algorithm
     */
    protected function isPointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $j = count($polygon) - 1;

        for ($i = 0; $i < count($polygon); $i++) {
            $xi = $polygon[$i]['longitude'];
            $yi = $polygon[$i]['latitude'];
            $xj = $polygon[$j]['longitude'];
            $yj = $polygon[$j]['latitude'];

            if (($yi > $lng) != ($yj > $lng) &&
                $lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }

            $j = $i;
        }

        return $inside;
    }
}
