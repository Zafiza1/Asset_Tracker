<?php

namespace App\Http\Resources;

use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantAssetCategory */
class TenantCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => Ref::of($this->parent)),
            'generic_category_id' => $this->generic_category_id,
            'generic_category' => $this->whenLoaded('genericCategory', fn () => Ref::of($this->genericCategory)),
            'depth' => Ref::depth($this->path),
            'attributes_count' => $this->whenHas('attributes_count'),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
