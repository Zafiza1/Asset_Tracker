<?php

namespace App\Http\Resources;

use App\Domain\Authorization\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'template_code' => $this->template_code,
            'is_locked' => $this->is_locked,
            'users_count' => $this->whenCounted('users'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('code')->sort()->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
