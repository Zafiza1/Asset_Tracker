<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for an email address to join one organization with the given access.
 * Only the SHA-256 hash of the token is stored; the token itself exists only in the email.
 * A pending invitation past expires_at counts as expired even before expireStale() marks it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $token_hash
 * @property string $invited_by
 * @property list<string> $role_ids
 * @property list<array{scope_type: string, ref_id?: string|null}> $scopes
 * @property string|null $employee_number
 * @property string|null $job_title
 * @property string|null $home_department_id
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $last_sent_at
 * @property int $send_count
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 */
class OrganizationInvitation extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_EXPIRED, self::STATUS_REVOKED];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Status as users see it: a pending invitation past its expiry is expired. */
    public function effectiveStatus(): string
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isPast() ? self::STATUS_EXPIRED : $this->status;
    }

    /** Filter on the effective status (see effectiveStatus()). */
    public function scopeWithEffectiveStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            self::STATUS_PENDING => $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now()),
            self::STATUS_EXPIRED => $query->where(fn ($q) => $q->where('status', self::STATUS_EXPIRED)
                ->orWhere(fn ($p) => $p->where('status', self::STATUS_PENDING)->where('expires_at', '<=', now()))),
            default => $query->where('status', $status),
        };
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
