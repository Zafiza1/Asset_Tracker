<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 120, 300];

    public function __construct(
        public WebhookDelivery $delivery
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookService $webhookService): void
    {
        $webhook = $this->delivery->webhook;

        if (!$webhook || !$webhook->active) {
            $this->delivery->markAsFailed('Webhook is inactive or does not exist');
            return;
        }

        try {
            $this->delivery->markForRetry();

            $signature = $webhookService->generateSignature(
                $this->delivery->payload,
                $webhook->secret
            );

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Event' => $this->delivery->event_type,
                'X-Webhook-Id' => $webhook->id,
                'X-Webhook-Delivery-Id' => $this->delivery->id,
                'X-Webhook-Timestamp' => now()->toIso8601String(),
                'User-Agent' => 'AssetTracker-Webhook/1.0',
            ])->timeout(30)->post($webhook->endpoint, $this->delivery->payload);

            if ($response->successful()) {
                $this->delivery->markAsDelivered(
                    $response->body(),
                    $response->status()
                );

                Log::info('Webhook delivered successfully', [
                    'webhook_id' => $webhook->id,
                    'delivery_id' => $this->delivery->id,
                    'event_type' => $this->delivery->event_type,
                    'status' => $response->status(),
                ]);
            } else {
                $this->handleFailure($response, $webhookService);
            }
        } catch (\Exception $e) {
            $this->handleException($e, $webhookService);
        }
    }

    protected function handleFailure($response, WebhookService $webhookService): void
    {
        $errorMessage = "HTTP {$response->status()}: {$response->body()}";

        if ($this->delivery->canRetry()) {
            $delay = $this->delivery->calculateNextRetryDelay();
            $this->release($delay);

            Log::warning('Webhook delivery failed, will retry', [
                'webhook_id' => $this->delivery->webhook_id,
                'delivery_id' => $this->delivery->id,
                'attempt' => $this->delivery->attempt,
                'next_retry_in' => $delay,
                'error' => $errorMessage,
            ]);
        } else {
            $this->delivery->markAsFailed($errorMessage);

            Log::error('Webhook delivery failed permanently', [
                'webhook_id' => $this->delivery->webhook_id,
                'delivery_id' => $this->delivery->id,
                'attempts' => $this->delivery->attempt,
                'error' => $errorMessage,
            ]);
        }
    }

    protected function handleException(\Exception $e, WebhookService $webhookService): void
    {
        $errorMessage = $e->getMessage();

        if ($this->delivery->canRetry()) {
            $delay = $this->delivery->calculateNextRetryDelay();
            $this->release($delay);

            Log::warning('Webhook delivery failed with exception, will retry', [
                'webhook_id' => $this->delivery->webhook_id,
                'delivery_id' => $this->delivery->id,
                'attempt' => $this->delivery->attempt,
                'next_retry_in' => $delay,
                'error' => $errorMessage,
            ]);
        } else {
            $this->delivery->markAsFailed($errorMessage);

            Log::error('Webhook delivery failed permanently with exception', [
                'webhook_id' => $this->delivery->webhook_id,
                'delivery_id' => $this->delivery->id,
                'attempts' => $this->delivery->attempt,
                'error' => $errorMessage,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->delivery->markAsFailed($exception->getMessage());

        Log::error('Webhook job failed permanently', [
            'webhook_id' => $this->delivery->webhook_id,
            'delivery_id' => $this->delivery->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
