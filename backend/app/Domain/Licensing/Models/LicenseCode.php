<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reusable license code for user account activation.
 * Each code can be assigned to a user and released when they leave (resign).
 * Codes follow the format: LIC-XXXX-XXXX-XXXX
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $status
 * @property string|null $assigned_to_user_id
 * @property Carbon|null $assigned_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LicenseCode extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_AVAILABLE, self::STATUS_ASSIGNED, self::STATUS_REVOKED];

    protected $fillable = ['code', 'status', 'assigned_to_user_id', 'assigned_at', 'notes'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    /** Filter on available codes for the current organization. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    /** Filter on assigned codes. */
    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ASSIGNED);
    }

    /** Filter on revoked codes. */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REVOKED);
    }

    /** Relationship to the organization member this code is currently assigned to. */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'assigned_to_user_id', 'user_id');
    }

    /** Relationship to the organization that owns this code. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Check if the code is currently available for assignment. */
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /** Check if the code is currently assigned to a user. */
    public function isAssigned(): bool
    {
        return $this->status === self::STATUS_ASSIGNED;
    }

    /** Mark the code as assigned to a user. */
    public function assignTo(string $userId): void
    {
        $this->forceFill([
            'status' => self::STATUS_ASSIGNED,
            'assigned_to_user_id' => $userId,
            'assigned_at' => now(),
        ])->save();
    }

    /** Release the code back to available status (when user resigns). */
    public function release(): void
    {
        $this->forceFill([
            'status' => self::STATUS_AVAILABLE,
            'assigned_to_user_id' => null,
            'assigned_at' => null,
        ])->save();
    }

    /** Revoke the code permanently. */
    public function revoke(): void
    {
        $this->forceFill([
            'status' => self::STATUS_REVOKED,
            'assigned_to_user_id' => null,
            'assigned_at' => null,
        ])->save();
    }
}
