<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vehicle */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'type_label' => Vehicle::typeLabel($this->type),
            'year' => $this->year,
            'chassis_number' => $this->chassis_number,
            'engine_number' => $this->engine_number,
            'fuel' => $this->fuel,
            'fuel_label' => $this->fuel === null ? null : (Vehicle::FUELS[$this->fuel] ?? $this->fuel),
            'capacity' => $this->capacity === null ? null : (float) $this->capacity,
            'stnk_expires_on' => $this->stnk_expires_on?->toDateString(),
            'kir_expires_on' => $this->kir_expires_on?->toDateString(),
            'status' => $this->status,
            // Tersedia / Sedang Digunakan, from the transactions not yet finished.
            'usage' => ($number = $this->resource->openDeliveryNumber()) === null ? Vehicle::AVAILABLE : Vehicle::IN_USE,
            'usage_label' => Vehicle::USAGES[$number === null ? Vehicle::AVAILABLE : Vehicle::IN_USE],
            'usage_delivery_number' => $number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
