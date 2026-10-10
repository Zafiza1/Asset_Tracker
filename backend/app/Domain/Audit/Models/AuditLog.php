<?php

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only. Written exclusively through AuditLogger; the DB rejects UPDATE/DELETE.
 * Not under the organization global scope because platform rows have organization_id NULL;
 * read paths filter explicitly and RLS enforces the boundary.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $actor_type
 * @property string|null $actor_user_id
 * @property string $action
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
