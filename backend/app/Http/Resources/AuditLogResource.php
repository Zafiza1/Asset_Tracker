<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /** Internal identifiers recorded by older entries that are no longer shown to users. */
    private const HIDDEN_FIELDS = ['asset_number'];

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
            // {code, name} of the object, when it can be named (see AuditEntityLabels).
            'entity_label' => $this->getAttribute('entity_label'),
            'before' => self::visible($this->before),
            'after' => self::visible($this->after),
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function visible(?array $values): ?array
    {
        return $values === null ? null : array_diff_key($values, array_flip(self::HIDDEN_FIELDS));
    }
}
