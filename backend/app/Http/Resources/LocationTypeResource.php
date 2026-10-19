<?php

namespace App\Http\Resources;

use App\Domain\GenericMaster\Models\LocationType;
use Illuminate\Http\Request;

/**
 * Location type as seen by an organization: built-in (platform, read-only) or its own.
 *
 * @property LocationType $resource
 */
class LocationTypeResource extends ReferenceResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'is_built_in' => $this->resource->isBuiltIn()];
    }
}
