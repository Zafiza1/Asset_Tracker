<?php

namespace App\Http\Resources;

use App\Domain\ReportDesigner\Models\ReportTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportTemplate */
class ReportTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'data_source' => $this->data_source,
            'status' => $this->status,
            'active_version' => $this->active_version,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'versions_count' => $this->whenCounted('versions'),
            // Latest draft (the editable working copy) and the published version new
            // exports use; both null until they exist.
            'draft_version' => $this->whenLoaded('versions', fn () => $this->versions
                ->firstWhere('status', 'draft')?->only(['id', 'version_number', 'status', 'published_at'])),
            'published_version' => $this->whenLoaded('versions', fn () => $this->versions
                ->firstWhere('status', 'published')?->only(['id', 'version_number', 'status', 'published_at'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
