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
            // Active bindings only: Device → Binding → Asset, with the
            // integration each device reports through (no secrets).
            'devices' => $this->whenLoaded('deviceBindings', fn () => $this->deviceBindings
                ->whereNull('unbound_at')
                ->filter(fn ($binding) => $binding->device)
                ->map(fn ($binding) => [
                    'system_id' => $binding->device->system_id,
                    'serial_number' => $binding->device->serial_number,
                    'name' => $binding->device->name,
                    'status' => $binding->device->status,
                    'type' => $binding->device->deviceType?->name,
                    'last_seen_at' => $binding->device->last_seen_at?->toIso8601String(),
                    'bound_at' => $binding->bound_at?->toIso8601String(),
                    'integration' => $binding->device->integration ? [
                        'id' => $binding->device->integration->id,
                        'name' => $binding->device->integration->name,
                        'type' => $binding->device->integration->type,
                        'status' => $binding->device->integration->status,
                    ] : null,
                ])->values()),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
