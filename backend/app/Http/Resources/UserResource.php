<?php

namespace App\Http\Resources;

use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'employee_number' => $this->employee_number,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'home_branch_id' => $this->home_branch_id,
            'home_department_id' => $this->home_department_id,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->id, 'code' => $r->code, 'name' => $r->name,
            ])->values()),
            'data_scopes' => $this->whenLoaded('dataScopes', fn () => $this->dataScopes->map(fn (UserDataScope $s) => [
                'scope_type' => $s->scope_type, 'ref_id' => $s->refId(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
