<?php

namespace App\Events;

use App\Models\Integration;

/**
 * integration.connected / integration.disconnected / integration.degraded —
 * emitted whenever an integration's status actually changes.
 */
class IntegrationStatusChanged extends WebhookTriggerable
{
    public function __construct(Integration $integration, ?string $previousStatus)
    {
        parent::__construct(
            'integration.' . $integration->status,
            $integration->organization_id,
            $integration->project_id,
            [
                'integration_id' => $integration->id,
                'type' => $integration->type,
                'name' => $integration->name,
                'status' => $integration->status,
                'previous_status' => $previousStatus,
            ]
        );
    }
}
