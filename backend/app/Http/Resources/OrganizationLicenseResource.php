<?php

namespace App\Http\Resources;

use App\Domain\Licensing\Models\OrganizationLicense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal notes and the revocation reason are written by platform staff for platform staff;
 * the organization sees the terms of its purchases.
 *
 * @mixin OrganizationLicense
 */
class OrganizationLicenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $platform = $request->is('api/platform/*');

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'seats' => $this->seats,
            'source' => $this->source,
            'reference' => $this->reference,
            'status' => $this->status(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'notes' => $this->when($platform, $this->notes),
            'revoke_reason' => $this->when($platform, $this->revoke_reason),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
