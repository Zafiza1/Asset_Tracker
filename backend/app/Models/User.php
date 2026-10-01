<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Traits\TenantScoping;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'status',
        'settings',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'settings' => 'array',
    ];

    // Relationships
    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
                    ->withPivot('role', 'permissions', 'joined_at')
                    ->withTimestamps();
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_users')
                    ->withPivot('role', 'permissions', 'joined_at')
                    ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
                    ->withPivot('organization_id', 'project_id')
                    ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
                    ->withPivot('organization_id', 'project_id')
                    ->withTimestamps();
    }

    public function defaultOrganization()
    {
        return $this->belongsTo(Organization::class, 'default_organization_id');
    }

    public function defaultProject()
    {
        return $this->belongsTo(Project::class, 'default_project_id');
    }

    // Tenant Scoping
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->whereHas('organizations', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        });
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->whereHas('projects', function ($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    }

    // Helper methods (legacy support for organization_users and project_users pivot roles)
    public function hasOrganizationRole(string $role, ?int $organizationId = null): bool
    {
        if ($organizationId) {
            return $this->organizations()
                       ->where('organization_id', $organizationId)
                       ->where('organization_users.role', $role)
                       ->exists();
        }

        return $this->organizations()->where('organization_users.role', $role)->exists();
    }

    public function hasProjectRole(string $role, ?int $projectId = null): bool
    {
        if ($projectId) {
            return $this->projects()
                       ->where('project_id', $projectId)
                       ->where('project_users.role', $role)
                       ->exists();
        }

        return $this->projects()->where('project_users.role', $role)->exists();
    }

    /**
     * A user can access a project when they are a member of it, or when they
     * belong to its organization and hold an organization-level role there
     * (organization-level roles apply to every project of the organization,
     * see grantsInContext()).
     */
    public function canAccessProject(int $projectId): bool
    {
        if ($this->projects()->where('project_id', $projectId)->exists()) {
            return true;
        }

        $organizationId = Project::withoutGlobalScope(TenantScope::class)
            ->whereKey($projectId)
            ->value('organization_id');

        return $organizationId !== null
            && $this->canAccessOrganization($organizationId)
            && $this->roles()
                ->wherePivot('organization_id', $organizationId)
                ->wherePivotNull('project_id')
                ->exists();
    }

    public function canAccessOrganization(int $organizationId): bool
    {
        return $this->organizations()->where('organization_id', $organizationId)->exists();
    }

    // Role & Permission methods

    /**
     * Constrain a user_roles / user_permissions query to the grants that apply
     * in the given context:
     *
     * - organization + project: grants for that project, plus the
     *   organization-level grants (project_id NULL) of that organization,
     *   which apply to every project in it;
     * - organization only: grants within that organization;
     * - project only: grants for that project;
     * - neither: global (platform-level) grants only.
     *
     * Callers must pass a project together with its own organization
     * (TenantMiddleware and the policies guarantee this).
     */
    protected function grantsInContext(BelongsToMany $query, ?int $organizationId, ?int $projectId): BelongsToMany
    {
        $table = $query->getTable();

        if ($organizationId) {
            $query->wherePivot('organization_id', $organizationId);
        }

        if ($projectId && $organizationId) {
            $query->where(function ($q) use ($table, $projectId) {
                $q->where("{$table}.project_id", $projectId)
                  ->orWhereNull("{$table}.project_id");
            });
        } elseif ($projectId) {
            $query->wherePivot('project_id', $projectId);
        }

        if (!$organizationId && !$projectId) {
            $query->where(function ($q) use ($table) {
                $q->whereNull("{$table}.organization_id")
                  ->whereNull("{$table}.project_id");
            });
        }

        return $query;
    }

    public function hasRole(string $roleSlug, ?int $organizationId = null, ?int $projectId = null): bool
    {
        return $this->grantsInContext($this->roles()->where('slug', $roleSlug), $organizationId, $projectId)
            ->exists();
    }

    public function hasPermission(string $permissionSlug, ?int $organizationId = null, ?int $projectId = null): bool
    {
        // Direct permissions
        $direct = $this->grantsInContext(
            $this->permissions()->where('slug', $permissionSlug),
            $organizationId,
            $projectId,
        );

        if ($direct->exists()) {
            return true;
        }

        // Permissions through roles
        $viaRole = $this->roles()->whereHas('permissions', function ($q) use ($permissionSlug) {
            $q->where('slug', $permissionSlug);
        });

        return $this->grantsInContext($viaRole, $organizationId, $projectId)->exists();
    }

    /**
     * Whether the user holds this permission (directly or via role) in ANY
     * context — global, or scoped to any organization/project they belong to.
     *
     * Use for abilities checked before a specific resource exists (e.g.
     * "create") where the caller has no single organization_id/project_id to
     * pass to hasPermission(); hasPermission() with no context only matches a
     * global grant, so an organization-scoped role would otherwise fail.
     */
    public function hasPermissionAnywhere(string $permissionSlug): bool
    {
        if ($this->permissions()->where('slug', $permissionSlug)->exists()) {
            return true;
        }

        return $this->roles()->whereHas('permissions', function ($q) use ($permissionSlug) {
            $q->where('slug', $permissionSlug);
        })->exists();
    }

    public function hasAnyPermission(array $permissionSlugs, ?int $organizationId = null, ?int $projectId = null): bool
    {
        foreach ($permissionSlugs as $slug) {
            if ($this->hasPermission($slug, $organizationId, $projectId)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissionSlugs, ?int $organizationId = null, ?int $projectId = null): bool
    {
        foreach ($permissionSlugs as $slug) {
            if (!$this->hasPermission($slug, $organizationId, $projectId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The highest role level the user holds in the context (see
     * grantsInContext()); platform admins outrank everyone. Used to stop a
     * user from granting or revoking roles above their own.
     */
    public function highestRoleLevel(?int $organizationId = null, ?int $projectId = null): int
    {
        if ($this->isPlatformAdmin()) {
            return PHP_INT_MAX;
        }

        return (int) $this->grantsInContext($this->roles(), $organizationId, $projectId)->max('level');
    }

    public function assignRole(string $roleSlug, ?int $organizationId = null, ?int $projectId = null): self
    {
        // Fail loudly: silently skipping an unknown slug leaves the user
        // without the access the caller intended.
        $role = Role::where('slug', $roleSlug)->first()
            ?? throw new \InvalidArgumentException("Unknown role [{$roleSlug}]");

        $this->roles()->syncWithoutDetaching([
            $role->id => [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
            ]
        ]);

        return $this;
    }

    public function removeRole(string $roleSlug, ?int $organizationId = null, ?int $projectId = null): self
    {
        $role = Role::where('slug', $roleSlug)->first();

        if ($role) {
            $query = $this->roles()->where('role_id', $role->id);

            if ($organizationId) {
                $query->wherePivot('organization_id', $organizationId);
            }

            if ($projectId) {
                $query->wherePivot('project_id', $projectId);
            }

            $query->detach();
        }

        return $this;
    }

    public function givePermission(string $permissionSlug, ?int $organizationId = null, ?int $projectId = null): self
    {
        $permission = Permission::where('slug', $permissionSlug)->first();

        if ($permission) {
            $this->permissions()->syncWithoutDetaching([
                $permission->id => [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                ]
            ]);
        }

        return $this;
    }

    public function revokePermission(string $permissionSlug, ?int $organizationId = null, ?int $projectId = null): self
    {
        $permission = Permission::where('slug', $permissionSlug)->first();

        if ($permission) {
            $query = $this->permissions()->where('permission_id', $permission->id);

            if ($organizationId) {
                $query->wherePivot('organization_id', $organizationId);
            }

            if ($projectId) {
                $query->wherePivot('project_id', $projectId);
            }

            $query->detach();
        }

        return $this;
    }

    /**
     * Replace the user's roles in exactly this context (organization and
     * project, either of which may be null). Roles held in any other context
     * are left untouched.
     */
    public function syncRoles(array $roleSlugs, ?int $organizationId = null, ?int $projectId = null): self
    {
        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id');

        DB::transaction(function () use ($roleIds, $organizationId, $projectId) {
            DB::table('user_roles')
                ->where('user_id', $this->id)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->delete();

            $now = now();
            DB::table('user_roles')->insert($roleIds->map(fn ($roleId) => [
                'user_id' => $this->id,
                'role_id' => $roleId,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        return $this;
    }

    public function getRolesForContext(?int $organizationId = null, ?int $projectId = null): array
    {
        return $this->grantsInContext($this->roles(), $organizationId, $projectId)
            ->pluck('slug')
            ->unique()
            ->values()
            ->toArray();
    }

    public function getPermissionsForContext(?int $organizationId = null, ?int $projectId = null): array
    {
        $directSlugs = $this->grantsInContext($this->permissions(), $organizationId, $projectId)
            ->pluck('slug')
            ->toArray();

        $roleIds = $this->grantsInContext($this->roles(), $organizationId, $projectId)->pluck('roles.id');

        $rolePermissions = Permission::whereHas('roles', function ($q) use ($roleIds) {
            $q->whereIn('roles.id', $roleIds);
        })->pluck('slug')->toArray();

        return array_values(array_unique(array_merge($directSlugs, $rolePermissions)));
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('platform-admin');
    }
}
