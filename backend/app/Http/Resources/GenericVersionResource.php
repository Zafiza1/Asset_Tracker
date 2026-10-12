<?php

namespace App\Http\Resources;

use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GenericDefinitionVersion */
class GenericVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'definition_id' => $this->definition_id,
            'definition' => $this->whenLoaded('definition', fn () => [...Ref::of($this->definition), 'status' => $this->definition->status]),
            'version_number' => $this->version_number,
            'status' => $this->status,
            'changelog' => $this->changelog,
            'migration_notes' => $this->migration_notes,
            'schema_hash' => $this->schema_hash,
            'released_at' => $this->released_at?->toIso8601String(),
            'deprecated_at' => $this->deprecated_at?->toIso8601String(),
            'attributes_count' => $this->whenCounted('attributeDefinitions'),
            'attributes' => AttributeDefinitionResource::collection($this->whenLoaded('attributeDefinitions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
