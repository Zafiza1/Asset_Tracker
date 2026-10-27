<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Licensing\Models\LicenseCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation as its organization's administrators see it. The token and its hash are
 * never part of any response.
 *
 * @mixin OrganizationInvitation
 */
class OrganizationInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'email' => $this->email,
            'status' => $this->effectiveStatus(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name])->values()),
            'scopes' => $this->scopes,
            'employee_number' => $this->employee_number,
            'job_title' => $this->job_title,
            'home_department_id' => $this->home_department_id,
            'license_code_id' => $this->license_code_id,
            'license_code' => $this->whenLoaded('licenseCode', fn () => $this->licenseCode ? [
                'id' => $this->licenseCode->id,
                'code' => $this->licenseCode->code,
            ] : null),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter ? ['id' => $this->inviter->id, 'name' => $this->inviter->name] : null),
            'expires_at' => $this->expires_at->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'send_count' => $this->send_count,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
