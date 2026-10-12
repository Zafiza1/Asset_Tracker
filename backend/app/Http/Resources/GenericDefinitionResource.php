<?php

namespace App\Http\Resources;

use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GenericAssetDefinition */
class GenericDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => Ref::of($this->category)),
            'status' => $this->status,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion === null ? null : [
                'id' => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'released_at' => $this->currentVersion->released_at?->toIso8601String(),
            ]),
            'versions' => GenericVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
