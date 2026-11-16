<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization dashboard shared with exactly one member or one role of the same
 * organization, at a SharePermission level.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $dashboard_id
 * @property string|null $user_id
 * @property string|null $role_id
 * @property string $permission
 * @property string|null $created_by
 */
class DashboardShare extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['user_id', 'role_id', 'permission'];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
