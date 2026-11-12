<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => Ref::of($this->parent)),
            // Lets the UI indent a flat list sorted by path.
            'depth' => Ref::depth($this->path),
            'children_count' => $this->whenHas('children_count'),
            'assets_count' => $this->whenHas('assets_count', fn () => (int) $this->assets_count),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
