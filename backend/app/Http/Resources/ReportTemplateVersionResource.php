<?php

namespace App\Http\Resources;

use App\Domain\ReportDesigner\Models\ReportTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportTemplateVersion */
class ReportTemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'design' => $this->design,
            'excel_config' => $this->excel_config,
            'change_note' => $this->change_note,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->whenLoaded('publisher', fn () => $this->publisher?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
