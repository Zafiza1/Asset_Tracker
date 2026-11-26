<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'delivery_role' => $this->delivery_role,
            'delivery_role_label' => Department::DELIVERY_ROLES[$this->delivery_role] ?? null,
            'employees_count' => $this->whenCounted('employees'),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
