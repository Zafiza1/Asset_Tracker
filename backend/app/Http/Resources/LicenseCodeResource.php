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
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ] : null),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'notes' => $this->notes,
            'reference' => $this->getAttribute('reference'),
            // The license this code was bought with (validity period), when the controller attached it.
            'license' => $this->whenLoaded('license', fn () => $this->license ? [
                'id' => $this->license->id,
                'kind' => $this->license->kind,
                'source' => $this->license->source,
                'status' => $this->license->status(),
                'starts_at' => $this->license->starts_at->toIso8601String(),
                'expires_at' => $this->license->expires_at->toIso8601String(),
            ] : null),
            // An open invitation that will give this (still available) code to its invitee on acceptance.
            'pending_invitation' => $this->whenLoaded('pendingInvitation', fn () => $this->pendingInvitation ? [
                'id' => $this->pendingInvitation->id,
                'email' => $this->pendingInvitation->email,
                'expires_at' => $this->pendingInvitation->expires_at?->toIso8601String(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
