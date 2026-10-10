<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Authorization\Services\OrgAdminGuard;
use App\Domain\Authorization\Services\RoleManagement;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant user administration. Always runs inside a tenant context; every referenced
 * role/branch/department/location is resolved through tenant-scoped queries, and the
 * composite foreign keys reject anything that slips through.
 */
final class UserManagement
{
    private const PROFILE_FIELDS = ['name', 'email', 'employee_number', 'job_title', 'phone', 'home_branch_id', 'home_department_id'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly RoleManagement $roles,
        private readonly OrgAdminGuard $adminGuard,
        private readonly SessionTerminator $sessions,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  profile fields + password
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function create(array $data, array $roleIds, array $scopes): User
    {
        $this->assertReferences($data);
        $roles = $this->resolveAssignableRoles($roleIds);

        return DB::transaction(function () use ($data, $roles, $scopes) {
            $user = new User(array_intersect_key($data, array_flip([...self::PROFILE_FIELDS, 'password'])));
            $user->forceFill([
                'user_type' => User::TYPE_TENANT,
                'organization_id' => $this->tenancy->organizationId(),
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
                'created_by' => $this->tenancy->tenant()->userId(),
            ])->save();

            $this->attachRoles($user, $roles->pluck('id')->all());
            $this->replaceScopes($user, $scopes);

            $this->audit->record('user.created', $user, after: $this->snapshot($user));

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        $this->assertReferences($data);

        return DB::transaction(function () use ($user, $data) {
            $before = $this->snapshot($user);
            $user->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)))->save();
            [$b, $a] = AuditLogger::diff($before, $this->snapshot($user));
            if ($a !== []) {
                $this->audit->record('user.updated', $user, before: $b, after: $a);
            }

            return $user;
        });
    }

    public function changeStatus(User $user, string $status): User
    {
        if ($user->id === $this->tenancy->tenant()->userId()) {
            throw ApiException::unprocessable('CANNOT_CHANGE_OWN_STATUS', 'Anda tidak dapat mengubah status akun Anda sendiri.');
        }
        if ($user->status === User::STATUS_DEACTIVATED) {
            throw ApiException::conflict('USER_DEACTIVATED', 'Akun yang sudah dinonaktifkan tidak dapat diaktifkan kembali.');
        }
        $this->roles->assertGrantable($this->permissionsOf($user));

        return DB::transaction(function () use ($user, $status) {
            $this->adminGuard->lock($user->organization_id);
            $before = $user->status;
            $user->forceFill(['status' => $status])->save();
            $this->adminGuard->assertHasActiveAdmin($user->organization_id);

            if ($status !== User::STATUS_ACTIVE) {
                $this->sessions->terminateAll($user);
            }
            $this->audit->record('user.status_changed', $user, before: ['status' => $before], after: ['status' => $status]);

            return $user;
        });
    }

    /** @param list<string> $roleIds */
    public function syncRoles(User $user, array $roleIds): User
    {
        $newRoles = $this->resolveAssignableRoles($roleIds);
        // Roles being removed must also be within the actor's own permissions.
        foreach ($user->roles()->get() as $current) {
            $this->roles->assertEditable($current);
        }

        return DB::transaction(function () use ($user, $newRoles) {
            $this->adminGuard->lock($user->organization_id);
            $before = $user->roles()->orderBy('code')->pluck('code')->all();
            $user->roles()->detach();
            $this->attachRoles($user, $newRoles->pluck('id')->all());
            $this->adminGuard->assertHasActiveAdmin($user->organization_id);

            $after = $user->roles()->orderBy('code')->pluck('code')->all();
            $this->audit->record('user.roles_changed', $user, before: ['roles' => $before], after: ['roles' => $after]);
            if ($user->id === $this->tenancy->tenant()->userId()) {
                $this->tenancy->tenant()->refresh();
            }

            return $user;
        });
    }

    /** @param list<array{scope_type: string, ref_id?: string|null}> $scopes */
    public function syncScopes(User $user, array $scopes): User
    {
        return DB::transaction(function () use ($user, $scopes) {
            $before = $this->scopeSnapshot($user);
            $this->replaceScopes($user, $scopes);
            $this->audit->record('user.scopes_changed', $user, before: ['scopes' => $before], after: ['scopes' => $this->scopeSnapshot($user)]);

            return $user;
        });
    }

    public function resetPassword(User $user, string $temporaryPassword): void
    {
        $this->roles->assertGrantable($this->permissionsOf($user));

        DB::transaction(function () use ($user, $temporaryPassword) {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();
            $this->sessions->terminateAll($user);
            $this->audit->record('user.password_reset', $user);
        });
    }

    /**
     * @param  list<string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveAssignableRoles(array $roleIds): Collection
    {
        $roleIds = array_values(array_unique($roleIds));
        $roles = Role::query()->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count($roleIds)) {
            throw ApiException::unprocessable('INVALID_ROLE', 'Role tidak ditemukan.');
        }
        foreach ($roles as $role) {
            $this->roles->assertEditable($role);
        }

        return $roles;
    }

    /** @param list<string> $roleIds */
    private function attachRoles(User $user, array $roleIds): void
    {
        $user->roles()->attach(array_fill_keys($roleIds, ['organization_id' => $user->organization_id]));
    }

    /** @param list<array{scope_type: string, ref_id?: string|null}> $scopes */
    private function replaceScopes(User $user, array $scopes): void
    {
        UserDataScope::query()->where('user_id', $user->id)->delete();

        $seen = [];
        foreach ($scopes as $scope) {
            $type = $scope['scope_type'];
            $ref = $type === 'organization' ? null : ($scope['ref_id'] ?? null);
            if (isset($seen[$type.'|'.$ref])) {
                continue;
            }
            $seen[$type.'|'.$ref] = true;

            $model = match ($type) {
                'organization' => null,
                'branch' => Branch::class,
                'department' => Department::class,
                'location' => Location::class,
                default => throw ApiException::unprocessable('INVALID_SCOPE', 'Jenis data scope tidak valid.'),
            };
            if ($model !== null && ($ref === null || ! $model::query()->whereKey($ref)->exists())) {
                throw ApiException::unprocessable('INVALID_SCOPE', 'Referensi data scope tidak ditemukan.');
            }

            UserDataScope::grant($user->organization_id, $user->id, $type, $ref);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(array $data): void
    {
        if (! empty($data['home_branch_id']) && ! Branch::query()->whereKey($data['home_branch_id'])->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Cabang tidak ditemukan.');
        }
        if (! empty($data['home_department_id']) && ! Department::query()->whereKey($data['home_department_id'])->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Departemen tidak ditemukan.');
        }
    }

    /** @return list<string> */
    private function permissionsOf(User $user): array
    {
        return $this->permissions->tenantPermissions($user);
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return $user->only([...self::PROFILE_FIELDS, 'status']);
    }

    /** @return list<string> */
    private function scopeSnapshot(User $user): array
    {
        return UserDataScope::query()->where('user_id', $user->id)->get()
            ->map(fn (UserDataScope $s) => $s->scope_type.($s->refId() ? ':'.$s->refId() : ''))
            ->sort()->values()->all();
    }
}
