<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for standard platform events (docs/architecture/events.md) that fan out
 * to webhooks and the audit log. Dispatched only once the surrounding DB
 * transaction commits, so subscribers never see a rolled-back change.
 */
abstract class WebhookTriggerable implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventType;

    public int $organizationId;

    public ?int $projectId;

    public array $payload;

    public function __construct(string $eventType, int $organizationId, ?int $projectId = null, array $payload = [])
    {
        $this->eventType = $eventType;
        $this->organizationId = $organizationId;
        $this->projectId = $projectId;
        $this->payload = $payload;
    }

    public function getWebhookPayload(): array
    {
        return array_merge([
            'event' => $this->eventType,
            'timestamp' => now()->toIso8601String(),
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
        ], $this->payload);
    }
}
