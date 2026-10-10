<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'actor_type' => $this->actor_type,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id, 'name' => $this->actor->name, 'email' => $this->actor->email,
            ] : null),
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'before' => $this->before,
            'after' => $this->after,
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
