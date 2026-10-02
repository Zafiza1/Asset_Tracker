<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'type' => $this->type,
            'provider' => $this->provider,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'config' => $this->whenLoaded('configs', function () {
                return $this->configs->mapWithKeys(fn ($config) => [$config->key => $config->displayValue()]);
            }),
            'last_connected_at' => $this->last_connected_at?->toIso8601String(),
            'last_health_check_at' => $this->last_health_check_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
