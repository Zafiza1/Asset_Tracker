<?php

namespace App\Listeners;

use App\Events\ModuleLifecycleChanged;
use App\Events\WebhookTriggerable;
use App\Services\WebhookService;

class DispatchWebhookDeliveries
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    public function handle($event): void
    {
        if ($event instanceof WebhookTriggerable) {
            $this->webhookService->triggerWebhooks(
                $event->eventType,
                $event->getWebhookPayload(),
                $event->organizationId,
                $event->projectId
            );
        } elseif ($event instanceof ModuleLifecycleChanged) {
            $this->webhookService->triggerWebhooks(
                $event->event,
                $event->payload(),
                $event->projectModule->organization_id,
                $event->projectModule->project_id
            );
        }
    }
}
