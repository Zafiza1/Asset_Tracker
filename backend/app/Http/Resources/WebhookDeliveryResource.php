<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'webhook_id' => $this->webhook_id,
            'event_log_id' => $this->event_log_id,
            'event_type' => $this->event_type,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempt' => $this->attempt,
            'max_attempts' => $this->max_attempts,
            'attempt_at' => $this->attempt_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
