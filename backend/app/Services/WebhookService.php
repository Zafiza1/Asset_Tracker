<?php

namespace App\Services;

use App\Models\EventLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookService
{
    public function createWebhook(array $data): Webhook
    {
        return Webhook::create([
            'organization_id' => $data['organization_id'],
            'project_id' => $data['project_id'] ?? null,
            'name' => $data['name'],
            'endpoint' => $data['endpoint'],
            'secret' => $data['secret'] ?? $this->generateSecret(),
            'events' => $data['events'],
            'active' => $data['active'] ?? true,
            'retry_policy' => $data['retry_policy'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function updateWebhook(Webhook $webhook, array $data): Webhook
    {
        $updateData = array_filter($data, function ($value) {
            return $value !== null;
        });

        if (isset($updateData['secret']) && $updateData['secret'] === '') {
            $updateData['secret'] = null;
        } elseif (isset($updateData['secret']) && $updateData['secret'] !== null) {
            // Keep existing secret if not provided
            unset($updateData['secret']);
        }

        $webhook->update($updateData);
        return $webhook->fresh();
    }

    public function deleteWebhook(Webhook $webhook): void
    {
        $webhook->delete();
    }

    public function getWebhooksForEvent(string $eventType, int $organizationId, ?int $projectId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Webhook::active()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->forEvent($eventType);

        return $query->get();
    }

    public function triggerWebhooks(string $eventType, array $payload, int $organizationId, ?int $projectId = null, ?EventLog $eventLog = null): void
    {
        $webhooks = $this->getWebhooksForEvent($eventType, $organizationId, $projectId);

        foreach ($webhooks as $webhook) {
            $this->dispatchWebhookDelivery($webhook, $eventType, $payload, $eventLog);
        }
    }

    protected function dispatchWebhookDelivery(Webhook $webhook, string $eventType, array $payload, ?EventLog $eventLog = null): void
    {
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_log_id' => $eventLog?->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'pending',
            'attempt' => 0,
            'max_attempts' => $webhook->getRetryPolicy()['max_attempts'] ?? 3,
        ]);

        dispatch(new \App\Jobs\DeliverWebhookJob($delivery));
    }

    public function regenerateSecret(Webhook $webhook): string
    {
        $newSecret = $this->generateSecret();
        $webhook->update(['secret' => $newSecret]);
        return $newSecret;
    }

    protected function generateSecret(): string
    {
        return 'wh_' . Str::random(32);
    }

    public function testWebhook(Webhook $webhook, array $testPayload = null): array
    {
        $testPayload = $testPayload ?? [
            'event' => 'webhook.test',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'message' => 'Webhook test successful',
            ],
        ];

        $signature = $this->generateSignature($testPayload, $webhook->secret);

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Event' => 'webhook.test',
                'X-Webhook-Id' => $webhook->id,
            ])->post($webhook->endpoint, $testPayload);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }

    public function generateSignature(array $payload, ?string $secret): string
    {
        if (!$secret) {
            return '';
        }

        $payloadJson = json_encode($payload);
        return hash_hmac('sha256', $payloadJson, $secret);
    }

    public function verifySignature(array $payload, string $signature, string $secret): bool
    {
        $expectedSignature = $this->generateSignature($payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    public function getWebhookDeliveryStats(Webhook $webhook): array
    {
        $deliveries = $webhook->deliveries();

        return [
            'total' => $deliveries->count(),
            'delivered' => $deliveries->delivered()->count(),
            'failed' => $deliveries->failed()->count(),
            'pending' => $deliveries->pending()->count(),
            'retrying' => $deliveries->retrying()->count(),
            'success_rate' => $deliveries->count() > 0
                ? round(($deliveries->delivered()->count() / $deliveries->count()) * 100, 2)
                : 0,
        ];
    }
}
