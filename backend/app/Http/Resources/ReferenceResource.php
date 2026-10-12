<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Small reference tables without sensitive columns: units, location types, generic and
 * tenant conditions, transaction types. Output is restricted to the listed columns.
 */
class ReferenceResource extends JsonResource
{
    private const FIELDS = ['id', 'code', 'name', 'symbol', 'quantity', 'description', 'severity', 'sort_order', 'generic_condition_id', 'status'];

    public function toArray(Request $request): array
    {
        $present = array_intersect(self::FIELDS, array_keys($this->resource->getAttributes()));

        return [
            ...$this->resource->only($present),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
