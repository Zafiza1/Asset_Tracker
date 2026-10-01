<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedWebhookDeliveriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $webhookId = null,
        public ?int $organizationId = null,
        public ?int $projectId = null
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $query = WebhookDelivery::shouldRetry();

        if ($this->webhookId) {
            $query->where('webhook_id', $this->webhookId);
        }

        if ($this->organizationId) {
            $query->whereHas('webhook', function ($q) {
                $q->where('organization_id', $this->organizationId);
            });
        }

        if ($this->projectId) {
            $query->whereHas('webhook', function ($q) {
                $q->where('project_id', $this->projectId);
            });
        }

        $deliveries = $query->get();

        foreach ($deliveries as $delivery) {
            $webhook = $delivery->webhook;

            if (!$webhook || !$webhook->active) {
                $delivery->markAsFailed('Webhook is inactive or does not exist');
                continue;
            }

            $nextRetryAt = $delivery->attempt_at
                ? $delivery->attempt_at->addSeconds($delivery->calculateNextRetryDelay())
                : now();

            if (now()->gte($nextRetryAt)) {
                dispatch(new DeliverWebhookJob($delivery));

                Log::info('Retrying webhook delivery', [
                    'delivery_id' => $delivery->id,
                    'webhook_id' => $webhook->id,
                    'attempt' => $delivery->attempt + 1,
                ]);
            }
        }

        Log::info('Webhook retry job completed', [
            'total_checked' => $deliveries->count(),
            'webhook_id' => $this->webhookId,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
        ]);
    }
}
