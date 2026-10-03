<?php

namespace App\Modules\Rental\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'overdue' => $this->isOverdue(),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ] : null),
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset ? [
                'system_id' => $this->asset->system_id,
                'serial_number' => $this->asset->serial_number,
                'name' => $this->asset->name,
            ] : null),
            'destination_location_id' => $this->destination_location_id,
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['id' => $this->destination->id, 'name' => $this->destination->name] : null),
            'returned_to_location_id' => $this->returned_to_location_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'daily_rate' => $this->daily_rate,
            'late_fee_per_day' => $this->late_fee_per_day,
            'rented_days' => $this->rented_days,
            'days_late' => $this->days_late,
            'rental_amount' => $this->rental_amount,
            'late_fee' => $this->late_fee,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
