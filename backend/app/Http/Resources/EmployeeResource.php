<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'position' => $this->position,
            'position_label' => Employee::POSITIONS[$this->position] ?? $this->position,
            'phone' => $this->phone,
            'license_number' => $this->license_number,
            'license_expires_on' => $this->license_expires_on?->toDateString(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
