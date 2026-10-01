<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'system_id' => $this->system_id,
            'serial_number' => $this->serial_number,
            'name' => $this->name,
            'description' => $this->description,
            'asset_type' => $this->asset_type,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'current_location' => $this->whenLoaded('locationAssignment', fn () => $this->locationAssignment?->location ? [
                'id' => $this->locationAssignment->location->id,
                'name' => $this->locationAssignment->location->name,
                'type' => $this->locationAssignment->location->type,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
