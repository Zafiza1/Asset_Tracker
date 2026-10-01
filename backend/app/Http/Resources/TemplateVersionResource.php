<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'version' => $this->version,
            'description' => $this->description,
            'default_modules' => $this->default_modules,
            'default_settings' => $this->default_settings,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'released_at' => $this->released_at?->toIso8601String(),
            'modules' => $this->whenLoaded('modules', function () {
                return $this->modules->map(function ($module) {
                    $resource = new ModuleResource($module);
                    return array_merge(
                        $resource->toArray(request()),
                        [
                            'version_constraint' => $module->pivot->version_constraint,
                            'required' => $module->pivot->required,
                            'default_config' => $module->pivot->default_config,
                            'sort_order' => $module->pivot->sort_order,
                        ]
                    );
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
