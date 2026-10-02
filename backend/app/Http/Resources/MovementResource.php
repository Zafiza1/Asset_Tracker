<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_location' => $this->whenLoaded('fromLocation', fn () => $this->fromLocation ? ['id' => $this->fromLocation->id, 'name' => $this->fromLocation->name] : null),
            'to_location' => $this->whenLoaded('toLocation', fn () => $this->toLocation ? ['id' => $this->toLocation->id, 'name' => $this->toLocation->name] : null),
            'source' => $this->source,
            'recorded_by' => $this->recorded_by,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
