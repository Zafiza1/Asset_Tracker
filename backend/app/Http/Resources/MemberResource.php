<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A user as a member of an organization or project. Expects the membership
 * pivot to be loaded and `scoped_roles` (role slugs granted at that level) to
 * be set by the controller.
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'membership' => $this->pivot?->role,
            'roles' => $this->scoped_roles ?? [],
            'joined_at' => $this->pivot?->joined_at
                ? Carbon::parse($this->pivot->joined_at)->toIso8601String()
                : null,
        ];
    }
}
