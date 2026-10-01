<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'status' => $this->status,
            'default_modules' => $this->default_modules,
            'default_settings' => $this->default_settings,
            'metadata' => $this->metadata,
            'current_version' => $this->when($this->currentVersion, function () {
                return new TemplateVersionResource($this->currentVersion);
            }),
            'current_version_id' => $this->current_version_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
