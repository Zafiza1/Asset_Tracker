<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One license purchase of an organization, recorded by the platform: the Asset Tracker license
 * itself (kind base, 1 seat) or a pack of additional seats. Valid from starts_at until
 * expires_at unless revoked. The terms never change once recorded (database trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property int $seats
 * @property string $source
 * @property string|null $reference
 * @property string|null $notes
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property string|null $granted_by
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoke_reason
 */
class OrganizationLicense extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KIND_BASE = 'base';

    public const KIND_SEATS = 'seats';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_TRIAL = 'trial';

    public const SOURCE_MIGRATION = 'migration';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            $this->starts_at->isFuture() => self::STATUS_SCHEDULED,
            default => self::STATUS_ACTIVE,
        };
    }

    /** Licenses in force right now. */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where('expires_at', '>', now());
    }
}
