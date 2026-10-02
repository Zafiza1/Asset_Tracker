<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn() => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn() => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn() => [
                'id' => $this->asset->id,
                'system_id' => $this->asset->system_id,
                'serial_number' => $this->asset->serial_number,
            ]),
            'device_id' => $this->device_id,
            'device' => $this->whenLoaded('device', fn() => [
                'id' => $this->device->id,
                'serial_number' => $this->device->serial_number,
                'system_id' => $this->device->system_id,
            ]),
            'integration_id' => $this->integration_id,
            'integration' => $this->whenLoaded('integration', fn() => [
                'id' => $this->integration->id,
                'name' => $this->integration->name,
                'type' => $this->integration->type,
            ]),
            'event_type' => $this->event_type,
            'source' => $this->source,
            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'status' => $this->status,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
