<?php

namespace App\Http\Resources;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GenericAssetCategory */
class GenericCategoryResource extends JsonResource
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
            'depth' => Ref::depth($this->path),
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'definitions_count' => $this->whenCounted('definitions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
