<?php

namespace App\Domain\Identity\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-organization side of a tenant account: one row per (organization, user).
 * Rows are never deleted; leaving an organization is status = deactivated.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $status
 * @property string|null $employee_number
 * @property string|null $job_title
 * @property string|null $home_department_id
 */
class OrganizationMember extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Fields an organization administrator manages for its own members. */
    public const PROFILE_FIELDS = ['employee_number', 'job_title', 'home_department_id'];

    protected $fillable = self::PROFILE_FIELDS;

    public function isActive(): bool
    {
        return $this->status === User::STATUS_ACTIVE;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'home_department_id');
    }
}
