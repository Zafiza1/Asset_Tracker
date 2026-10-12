<?php

namespace App\Http\Resources;

use App\Domain\TenantMaster\Models\TenantAttributeOverride;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantDefinitionVersion */
class TenantVersionResource extends JsonResource
{
    /** The frozen schema can be large; it is only included on the version detail endpoint. */
    public bool $withSchema = false;

    public function withSchema(): self
    {
        $this->withSchema = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_definition_id' => $this->tenant_definition_id,
            'definition' => $this->whenLoaded('definition', fn () => [...Ref::of($this->definition), 'status' => $this->definition->status]),
            'version_number' => $this->version_number,
            'status' => $this->status,
            'changelog' => $this->changelog,
            'generic_definition_version_id' => $this->generic_definition_version_id,
            'generic_version' => $this->whenLoaded('genericVersion', fn () => [
                'id' => $this->genericVersion->id,
                'version_number' => $this->genericVersion->version_number,
                'status' => $this->genericVersion->status,
            ]),
            'schema_hash' => $this->schema_hash,
            'effective_schema' => $this->when($this->withSchema, fn () => $this->effective_schema),
            'released_at' => $this->released_at?->toIso8601String(),
            'deprecated_at' => $this->deprecated_at?->toIso8601String(),
            'attributes' => AttributeDefinitionResource::collection($this->whenLoaded('tenantAttributes')),
            'overrides' => $this->whenLoaded('overrides', fn () => $this->overrides->map(fn (TenantAttributeOverride $o) => [
                'generic_attribute_key' => $o->generic_attribute_key,
                'overrides' => (object) $o->overrides,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
