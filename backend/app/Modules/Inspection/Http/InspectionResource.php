<?php

namespace App\Modules\Inspection\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionResource extends JsonResource
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
                'asset_type' => $this->asset->asset_type,
            ] : null),
            'checklist_id' => $this->checklist_id,
            'checklist' => $this->whenLoaded('checklist', fn () => $this->checklist ? [
                'id' => $this->checklist->id,
                'name' => $this->checklist->name,
                // The live items, for filling in a scheduled inspection.
                'items' => $this->status === 'scheduled' ? $this->checklist->items : null,
            ] : null),
            'status' => $this->status,
            'result' => $this->result,
            'overdue' => $this->status === 'scheduled' && $this->scheduled_at?->isPast(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'performed_at' => $this->performed_at?->toIso8601String(),
            'next_due_at' => $this->next_due_at?->toIso8601String(),
            'checklist_snapshot' => $this->checklist_snapshot,
            'answers' => $this->answers,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'performed_by' => $this->performed_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
