<?php

namespace App\Modules\Delivery\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ] : null),
            'destination_location_id' => $this->destination_location_id,
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? [
                'id' => $this->destination->id,
                'name' => $this->destination->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'asset_id' => $item->asset_id,
                'system_id' => $item->asset?->system_id,
                'serial_number' => $item->asset?->serial_number,
                'name' => $item->asset?->name,
                'status' => $item->status,
                'delivered_at' => $item->delivered_at?->toIso8601String(),
                'returned_at' => $item->returned_at?->toIso8601String(),
                'returned_to_location_id' => $item->returned_to_location_id,
            ])->values()),
            'items_count' => $this->whenCounted('items'),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'received_by' => $this->received_by,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
