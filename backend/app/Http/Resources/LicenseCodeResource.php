<?php

namespace App\Http\Resources;

use App\Domain\Licensing\Models\LicenseCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LicenseCode */
class LicenseCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser && $this->assignedUser->user ? [
                'id' => $this->assignedUser->user_id,
                'name' => $this->assignedUser->user->name,
                'email' => $this->assignedUser->user->email,
            ] : null),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
