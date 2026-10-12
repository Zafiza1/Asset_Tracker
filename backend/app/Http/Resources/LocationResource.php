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
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => Ref::of($this->branch)),
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => Ref::of($this->parent)),
            'location_type_id' => $this->location_type_id,
            'location_type' => $this->whenLoaded('locationType', fn () => Ref::of($this->locationType)),
            // Lets the UI indent a flat list sorted by path.
            'depth' => Ref::depth($this->path),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
