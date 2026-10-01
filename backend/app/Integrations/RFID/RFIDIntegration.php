<?php

namespace App\Integrations\RFID;

use App\Integrations\Contracts\IntegrationContract;
use App\Models\Integration;
use App\Models\Device;
use App\Models\EventLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class RFIDIntegration implements IntegrationContract
{
    protected array $config = [];

    public function connect(Integration $integration): bool
    {
        $this->config = $this->loadConfig($integration);

        try {
            // Test connection to RFID provider/reader
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                    'Content-Type' => 'application/json',
                ])
                ->get($this->config['endpoint'] ?? '');

            return $response->successful();
        } catch (Exception $e) {
            Log::error('RFID connection failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function disconnect(Integration $integration): bool
    {
        // For RFID, disconnection is mostly logical
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
            Log::error('RFID disconnection failed', [
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
            Log::error('RFID configuration validation failed', [
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
                    'message' => 'RFID connection successful',
                    'details' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => 'RFID connection failed: ' . $response->body(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'RFID connection error: ' . $e->getMessage(),
            ];
        }
    }

    public function receive(Integration $integration): array
    {
        $this->config = $this->loadConfig($integration);

        try {
            // Poll for RFID events from the provider
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
                ])
                ->get($this->config['endpoint'] . '/events', [
                    'since' => $this->config['last_poll_at'] ?? now()->subMinutes(5)->toIso8601String(),
                ]);

            if ($response->successful()) {
                $events = $response->json('events', []);
                return $events;
            }

            return [];
        } catch (Exception $e) {
            Log::error('RFID receive failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function normalize(array $rawData): array
    {
        // Normalize RFID detection event to standard format
        // Expected raw format: { tag_id, reader_id, timestamp, rssi, location }

        return [
            'event_type' => 'asset.detected',
            'payload' => [
                'tag_id' => $rawData['tag_id'] ?? null,
                'reader_id' => $rawData['reader_id'] ?? null,
                'device_serial' => $rawData['device_serial'] ?? null,
                'timestamp' => $rawData['timestamp'] ?? now()->toIso8601String(),
                'rssi' => $rawData['rssi'] ?? null,
                'location' => $rawData['location'] ?? null,
                'antenna_port' => $rawData['antenna_port'] ?? null,
            ],
            'metadata' => [
                'source_type' => 'rfid',
                'reader_type' => $rawData['reader_type'] ?? 'unknown',
                'read_count' => $rawData['read_count'] ?? 1,
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
        return 'rfid';
    }

    public function getName(): string
    {
        return 'RFID Integration';
    }

    public function getSupportedEventTypes(): array
    {
        return [
            'asset.detected',
            'asset.moved',
            'device.connected',
            'device.disconnected',
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
     * Ingest a single RFID tag read event
     * This is called when an RFID reader sends a real-time event
     */
    public function ingestTagRead(Integration $integration, array $tagData): EventLog
    {
        $normalized = $this->normalize($tagData);

        return EventLog::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'integration_id' => $integration->id,
            'event_type' => $normalized['event_type'],
            'source' => 'rfid',
            'payload' => $normalized['payload'],
            'metadata' => array_merge($normalized['metadata'], [
                'integration_id' => $integration->id,
                'raw_data' => $tagData,
            ]),
            'occurred_at' => $normalized['payload']['timestamp'],
            'status' => 'pending',
        ]);
    }

    /**
     * Resolve asset from RFID tag via device binding
     */
    public function resolveAssetFromTag(string $tagId, Integration $integration): ?\App\Models\Asset
    {
        // Find device by serial number (tag_id)
        $device = Device::where('serial_number', $tagId)
            ->where('project_id', $integration->project_id)
            ->first();

        if (!$device) {
            return null;
        }

        // Get currently bound asset
        return $device->boundAsset();
    }
}
