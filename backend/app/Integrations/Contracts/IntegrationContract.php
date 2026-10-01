<?php

namespace App\Integrations\Contracts;

use App\Models\Integration;
use App\Models\EventLog;

interface IntegrationContract
{
    /**
     * Connect to the integration service.
     *
     * @param Integration $integration
     * @return bool
     * @throws \Exception
     */
    public function connect(Integration $integration): bool;

    /**
     * Disconnect from the integration service.
     *
     * @param Integration $integration
     * @return bool
     * @throws \Exception
     */
    public function disconnect(Integration $integration): bool;

    /**
     * Configure the integration with provided settings.
     *
     * @param Integration $integration
     * @param array $config
     * @return bool
     * @throws \Exception
     */
    public function configure(Integration $integration, array $config): bool;

    /**
     * Test the connection to the integration service.
     *
     * @param Integration $integration
     * @return array{success: bool, message: string, details?: array}
     */
    public function testConnection(Integration $integration): array;

    /**
     * Receive data from the integration source.
     *
     * @param Integration $integration
     * @return array
     * @throws \Exception
     */
    public function receive(Integration $integration): array;

    /**
     * Normalize raw data into standard event format.
     *
     * @param array $rawData
     * @return array{event_type: string, payload: array, metadata: array}
     */
    public function normalize(array $rawData): array;

    /**
     * Perform health check on the integration.
     *
     * @param Integration $integration
     * @return array{status: 'healthy'|'degraded'|'unhealthy', message: string, details?: array}
     */
    public function healthCheck(Integration $integration): array;

    /**
     * Get the integration type identifier.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get the integration name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get supported event types for this integration.
     *
     * @return array<string>
     */
    public function getSupportedEventTypes(): array;

    /**
     * Validate configuration before saving.
     *
     * @param array $config
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(array $config): array;
}
