<?php

namespace App\Domain\Identity\Models;

use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\MissingTenantContext;
use App\Domain\Shared\Tenancy\Tenancy;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A global account. Tenant users belong to one or more organizations through
 * organization_members (same login for every organization); platform users belong to none.
 * user_type is immutable (enforced by a DB trigger). email_verified_at is set once the owner
 * proved access to the address; only verified accounts can accept organization invitations.
 *
 * @property string $id
 * @property string $user_type
 * @property string|null $last_organization_id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property bool $must_change_password
 * @property Carbon|null $email_verified_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, VerifiesEmail;

    public const TYPE_TENANT = 'tenant';

    public const TYPE_PLATFORM = 'platform';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'must_change_password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'email_verified_at' => 'datetime',
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
     * Members of the current tenant only. Users are not under the organization global scope
     * (one account spans several organizations, and login must find users before a tenant
     * is known), so tenant code paths must go through this scope.
     */
    public function scopeOfCurrentOrganization(Builder $query): Builder
    {
        $tenancy = app(Tenancy::class);
        if ($tenancy->hasTenant()) {
            return $query->where('users.user_type', self::TYPE_TENANT)->whereHas('membership');
        }
        if (! $tenancy->isUnrestricted()) {
            throw new MissingTenantContext;
        }

        return $query;
    }

    /** Route model binding never resolves a user who is not a member of the current organization. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query->ofCurrentOrganization(), $value, $field);
    }

    /**
     * Membership in the current organization. Only meaningful inside a tenant context,
     * where the organization global scope narrows it to exactly one row.
     */
    public function membership(): HasOne
    {
        return $this->hasOne(OrganizationMember::class);
    }

    /** Every membership of this account (unscoped only outside a tenant context). */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function lastOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'last_organization_id');
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
