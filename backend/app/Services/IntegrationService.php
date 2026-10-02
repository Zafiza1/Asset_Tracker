<?php

namespace App\Services;

use App\Events\IntegrationStatusChanged;
use App\Integrations\Contracts\IntegrationContract;
use App\Integrations\RFID\RFIDIntegration;
use App\Integrations\GPS\GPSIntegration;
use App\Models\Integration;
use App\Models\IntegrationConfig;
use App\Models\EventLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class IntegrationService
{
    protected array $integrations = [];

    public function __construct()
    {
        $this->registerIntegrations();
    }

    protected function registerIntegrations(): void
    {
        // Register integration implementations
        $this->integrations['rfid'] = new RFIDIntegration();
        $this->integrations['gps'] = new GPSIntegration();
        // Future integrations will be registered here:
        // $this->integrations['ble'] = new BLEIntegration();
        // $this->integrations['nfc'] = new NFCIntegration();
    }

    public function getIntegration(string $type): ?IntegrationContract
    {
        return $this->integrations[$type] ?? null;
    }

    public function getAvailableIntegrations(): array
    {
        return array_keys($this->integrations);
    }

    public function createIntegration(array $data): Integration
    {
        return DB::transaction(function () use ($data) {
            $integration = Integration::create([
                'organization_id' => $data['organization_id'],
                'project_id' => $data['project_id'],
                'name' => $data['name'],
                'type' => $data['type'],
                'provider' => $data['provider'] ?? null,
                'status' => 'disconnected',
                'metadata' => $data['metadata'] ?? [],
            ]);

            if (isset($data['config']) && is_array($data['config'])) {
                foreach ($data['config'] as $key => $value) {
                    IntegrationConfig::create([
                        'integration_id' => $integration->id,
                        'key' => $key,
                        'value' => $value,
                        'is_secret' => $this->isSecretKey($key),
                    ]);
                }
            }

            return $integration;
        });
    }

    public function updateIntegration(Integration $integration, array $data): Integration
    {
        return DB::transaction(function () use ($integration, $data) {
            $integration->update([
                'name' => $data['name'] ?? $integration->name,
                'provider' => $data['provider'] ?? $integration->provider,
                'metadata' => $data['metadata'] ?? $integration->metadata,
            ]);

            if (isset($data['config']) && is_array($data['config'])) {
                foreach ($data['config'] as $key => $value) {
                    IntegrationConfig::updateOrCreate(
                        [
                            'integration_id' => $integration->id,
                            'key' => $key,
                        ],
                        [
                            'value' => $value,
                            'is_secret' => $this->isSecretKey($key),
                        ]
                    );
                }
            }

            return $integration->fresh();
        });
    }

    public function connectIntegration(Integration $integration): array
    {
        $integrationContract = $this->getIntegration($integration->type);

        if (!$integrationContract) {
            return [
                'success' => false,
                'message' => "Integration type '{$integration->type}' not found",
            ];
        }

        try {
            $result = $integrationContract->connect($integration);

            if ($result) {
                $this->transition($integration, 'connected', ['last_connected_at' => now()]);

                return [
                    'success' => true,
                    'message' => 'Integration connected successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to connect integration',
            ];
        } catch (Exception $e) {
            Log::error("Integration connection failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function disconnectIntegration(Integration $integration): array
    {
        $integrationContract = $this->getIntegration($integration->type);

        if (!$integrationContract) {
            return [
                'success' => false,
                'message' => "Integration type '{$integration->type}' not found",
            ];
        }

        try {
            $result = $integrationContract->disconnect($integration);

            if ($result) {
                $this->transition($integration, 'disconnected');

                return [
                    'success' => true,
                    'message' => 'Integration disconnected successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to disconnect integration',
            ];
        } catch (Exception $e) {
            Log::error("Integration disconnection failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function testConnection(Integration $integration): array
    {
        $integrationContract = $this->getIntegration($integration->type);

        if (!$integrationContract) {
            return [
                'success' => false,
                'message' => "Integration type '{$integration->type}' not found",
            ];
        }

        try {
            $result = $integrationContract->testConnection($integration);

            if ($result['success']) {
                $integration->update([
                    'last_health_check_at' => now(),
                ]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error("Integration test connection failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function healthCheck(Integration $integration): array
    {
        $integrationContract = $this->getIntegration($integration->type);

        if (!$integrationContract) {
            return [
                'status' => 'unhealthy',
                'message' => "Integration type '{$integration->type}' not found",
            ];
        }

        try {
            $result = $integrationContract->healthCheck($integration);

            // A health check never connects a disconnected integration; it only
            // moves a live one between connected and degraded (Section: ERROR HANDLING).
            $integration->update(['last_health_check_at' => now()]);
            if ($integration->status !== 'disconnected') {
                $this->transition($integration, $result['status'] === 'healthy' ? 'connected' : 'degraded');
            }

            return $result;
        } catch (Exception $e) {
            Log::error("Integration health check failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function processEvent(array $rawData, Integration $integration): EventLog
    {
        $integrationContract = $this->getIntegration($integration->type);

        if (!$integrationContract) {
            throw new Exception("Integration type '{$integration->type}' not found");
        }

        $normalized = $integrationContract->normalize($rawData);

        return DB::transaction(function () use ($normalized, $integration, $rawData) {
            $eventLog = EventLog::create([
                'organization_id' => $integration->organization_id,
                'project_id' => $integration->project_id,
                'event_type' => $normalized['event_type'],
                'source' => $integration->type,
                'payload' => $normalized['payload'],
                'metadata' => array_merge($normalized['metadata'], [
                    'integration_id' => $integration->id,
                    'raw_data' => $rawData,
                ]),
                'occurred_at' => $normalized['payload']['timestamp'] ?? now(),
                'status' => 'pending',
            ]);

            // Dispatch event for processing
            // event(new IntegrationEventReceived($eventLog));

            return $eventLog;
        });
    }

    /**
     * Change an integration's status and publish integration.<status> when
     * it actually changed.
     */
    protected function transition(Integration $integration, string $status, array $extra = []): void
    {
        $previous = $integration->status;
        $integration->update(array_merge($extra, ['status' => $status]));

        if ($previous !== $status) {
            IntegrationStatusChanged::dispatch($integration, $previous);
        }
    }

    public function getIntegrationConfig(Integration $integration, bool $includeSecrets = false): array
    {
        $query = $integration->configs();

        if (!$includeSecrets) {
            $query->notSecret();
        }

        return $query->pluck('value', 'key')->toArray();
    }

    protected function isSecretKey(string $key): bool
    {
        $secretKeys = [
            'api_key',
            'secret',
            'password',
            'token',
            'private_key',
            'access_token',
            'refresh_token',
        ];

        return in_array(strtolower($key), $secretKeys) || str_contains(strtolower($key), 'secret');
    }

    public function validateIntegrationConfig(string $type, array $config): array
    {
        $integrationContract = $this->getIntegration($type);

        if (!$integrationContract) {
            return [
                'valid' => false,
                'errors' => ["Integration type '{$type}' not found"],
            ];
        }

        return $integrationContract->validateConfig($config);
    }
}
