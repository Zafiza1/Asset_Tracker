<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
