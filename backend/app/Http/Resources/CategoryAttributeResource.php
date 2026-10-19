<?php

namespace App\Http\Resources;

use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantCategoryAttribute */
class CategoryAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'data_type' => $this->data_type,
            'is_required' => $this->is_required,
            'options' => $this->options,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => Ref::of($this->category)),
            'sort_order' => $this->sort_order,
            'status' => $this->status,
        ];
    }
}
