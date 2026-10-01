<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system_id' => $this->system_id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'device_type_id' => $this->device_type_id,
            'integration_id' => $this->integration_id,
            'serial_number' => $this->serial_number,
            'name' => $this->name,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'device_type' => $this->whenLoaded('deviceType', function () {
                return [
                    'id' => $this->deviceType->id,
                    'name' => $this->deviceType->name,
                    'slug' => $this->deviceType->slug,
                ];
            }),
            'integration' => $this->whenLoaded('integration', function () {
                return [
                    'id' => $this->integration->id,
                    'name' => $this->integration->name,
                    'type' => $this->integration->type,
                ];
            }),
            'current_binding' => $this->whenLoaded('currentBinding', function () {
                $binding = $this->currentBinding->first();
                if (!$binding) {
                    return null;
                }

                return [
                    'asset_id' => $binding->asset_id,
                    'asset_system_id' => $binding->asset?->system_id,
                    'asset_name' => $binding->asset?->name,
                    'bound_at' => $binding->bound_at->toIso8601String(),
                ];
            }),
            'is_bound' => $this->isBound(),
            'is_online' => $this->isOnline(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
