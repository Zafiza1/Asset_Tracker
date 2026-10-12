<?php

namespace App\Http\Resources;

use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantAssetDefinition */
class TenantDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'generic_definition_id' => $this->generic_definition_id,
            'generic_definition' => $this->whenLoaded('genericDefinition', fn () => Ref::of($this->genericDefinition)),
            'tenant_category_id' => $this->tenant_category_id,
            'category' => $this->whenLoaded('category', fn () => Ref::of($this->category)),
            'status' => $this->status,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion === null ? null : [
                'id' => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'released_at' => $this->currentVersion->released_at?->toIso8601String(),
            ]),
            'draft_version' => $this->whenLoaded('draftVersion', fn () => $this->draftVersion === null ? null : [
                'id' => $this->draftVersion->id,
                'version_number' => $this->draftVersion->version_number,
            ]),
            'versions' => TenantVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
