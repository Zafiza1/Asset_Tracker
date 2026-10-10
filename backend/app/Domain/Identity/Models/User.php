<?php

namespace App\Domain\Identity\Models;

use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\MissingTenantContext;
use App\Domain\Shared\Tenancy\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * One account = one company (tenant user) or a platform user without organization.
 * organization_id and user_type are immutable (enforced by a DB trigger).
 *
 * @property string $id
 * @property string $user_type
 * @property string|null $organization_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property bool $must_change_password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids;

    public const TYPE_TENANT = 'tenant';

    public const TYPE_PLATFORM = 'platform';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'name', 'email', 'password', 'employee_number', 'job_title', 'phone',
        'home_branch_id', 'home_department_id', 'must_change_password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isTenantUser(): bool
    {
        return $this->user_type === self::TYPE_TENANT;
    }

    public function isPlatformUser(): bool
    {
        return $this->user_type === self::TYPE_PLATFORM;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Users of the current tenant only. Users are not under the organization global scope
     * (platform users have no organization, and login must find users before a tenant
     * is known), so tenant code paths must go through this scope.
     */
    public function scopeOfCurrentOrganization(Builder $query): Builder
    {
        $tenancy = app(Tenancy::class);
        if ($tenancy->hasTenant()) {
            return $query->where('users.organization_id', $tenancy->organizationId())
                ->where('users.user_type', self::TYPE_TENANT);
        }
        if (! $tenancy->isUnrestricted()) {
            throw new MissingTenantContext;
        }

        return $query;
    }

    /** Route model binding never resolves a user of another organization. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query->ofCurrentOrganization(), $value, $field);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function homeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'home_department_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('organization_id');
    }

    public function dataScopes(): HasMany
    {
        return $this->hasMany(UserDataScope::class);
    }
}
