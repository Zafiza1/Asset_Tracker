<?php

namespace App\Http\Resources;

use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generic or tenant attribute definition.
 *
 * @mixin GenericAttributeDefinition|TenantAttributeDefinition
 */
class AttributeDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $generic = $this->resource instanceof GenericAttributeDefinition;

        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'help_text' => $this->help_text,
            'data_type' => $this->data_type,
            'is_required' => $this->is_required,
            'validation' => (object) ($this->validation ?? []),
            'options' => $this->options,
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->toSpec()),
            'default_value' => $this->default_value,
            'is_searchable' => $this->is_searchable,
            'is_controlled' => $generic && $this->is_controlled,
            'overridable' => $generic ? ($this->overridable ?? []) : [],
            'sort_order' => $this->sort_order,
        ];
    }
}
