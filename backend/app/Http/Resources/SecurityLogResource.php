<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SecurityLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn() => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'details' => $this->details,
            'is_suspicious' => $this->is_suspicious,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
