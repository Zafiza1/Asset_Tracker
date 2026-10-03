<?php

namespace App\Modules\Maintenance\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset ? [
                'system_id' => $this->asset->system_id,
                'serial_number' => $this->asset->serial_number,
                'name' => $this->asset->name,
            ] : null),
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'overdue' => $this->status === 'scheduled' && $this->scheduled_at?->isPast(),
            'notes' => $this->notes,
            'cost' => $this->cost,
            'metadata' => $this->metadata,
            'previous_record_id' => $this->previous_record_id,
            'created_by' => $this->created_by,
            'completed_by' => $this->completed_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
