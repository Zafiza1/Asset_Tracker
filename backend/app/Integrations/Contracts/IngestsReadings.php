<?php

namespace App\Integrations\Contracts;

use App\Models\EventLog;
use App\Models\Integration;

/**
 * Turns one raw reading pushed by a gateway/provider into a normalized,
 * stored event (and any resulting Core updates via Core services).
 * Implementations are registered in config/platform.php "ingestors".
 */
interface IngestsReadings
{
    /**
     * @throws \Exception when the reading is malformed
     */
    public function ingest(Integration $integration, array $reading): EventLog;
}
