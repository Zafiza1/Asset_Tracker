<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Authorization\Services\OrgAdminGuard;
use App\Domain\Authorization\Services\RoleManagement;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant user administration. Always runs inside a tenant context; every referenced
 * role/department/location is resolved through tenant-scoped queries, and the
 * composite foreign keys reject anything that slips through.
 *
 * One account can belong to several organizations. An administrator manages the
 * membership in their own organization (status, roles, scopes, job data). Account-level
 * data (name, email, phone, password) is shared by every organization, so it can only be
 * changed here while the account belongs to this organization alone.
 */
final class UserManagement
{
    private const ACCOUNT_FIELDS = ['name', 'email', 'phone'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly RoleManagement $roles,
        private readonly OrgAdminGuard $adminGuard,
        private readonly SessionTerminator $sessions,
        private readonly PermissionResolver $permissions,
        private readonly OrganizationAccess $access,
    ) {}

    /**
     * Adds a member: a new account, or an existing tenant account with the same email
     * (it keeps its password and profile and simply gains access to this organization).
     *
     * @param  array<string, mixed>  $data  profile fields + password (new accounts only)
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function create(array $data, array $roleIds, array $scopes): User
    {
        $this->assertReferences($data);
        $roles = $this->resolveAssignableRoles($roleIds);
        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing !== null && ! $existing->isTenantUser()) {
            throw ApiException::unprocessable('EMAIL_TAKEN', 'Email sudah dipakai oleh akun lain.');
        }
        if ($existing !== null && OrganizationMember::query()->where('user_id', $existing->id)->exists()) {
            throw ApiException::conflict('ALREADY_MEMBER', 'Akun dengan email ini sudah menjadi anggota organisasi.');
        }

        return DB::transaction(function () use ($data, $roles, $scopes, $existing) {
            $actorId = $this->tenancy->tenant()->userId();
            $user = $existing;
            if ($user === null) {
                $user = new User(array_intersect_key($data, array_flip([...self::ACCOUNT_FIELDS, 'password'])));
                $user->forceFill([
                    'user_type' => User::TYPE_TENANT,
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => true,
                    'created_by' => $actorId,
                ])->save();
            }

            $member = new OrganizationMember(array_intersect_key($data, array_flip(OrganizationMember::PROFILE_FIELDS)));
            $member->forceFill(['user_id' => $user->id, 'status' => User::STATUS_ACTIVE, 'created_by' => $actorId])->save();
            $user->setRelation('membership', $member);

            $this->attachRoles($user, $roles->pluck('id')->all());
            $this->replaceScopes($user, $scopes);

            $this->audit->record($existing === null ? 'user.created' : 'user.member_added', $user, after: $this->snapshot($user));

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        $this->assertReferences($data);
        $member = $this->membership($user);
        $account = array_intersect_key($data, array_flip(self::ACCOUNT_FIELDS));
        if (array_diff_assoc(array_map('strval', $account), array_map('strval', $user->only(array_keys($account)))) !== []) {
            $this->assertExclusive($user);
        }

        return DB::transaction(function () use ($user, $member, $data, $account) {
            $before = $this->snapshot($user);
            $user->fill($account)->save();
            $member->fill(array_intersect_key($data, array_flip(OrganizationMember::PROFILE_FIELDS)))->save();
            [$b, $a] = AuditLogger::diff($before, $this->snapshot($user));
            if ($a !== []) {
                $this->audit->record('user.updated', $user, before: $b, after: $a);
            }

            return $user;
        });
    }

    /** Changes the status of the membership in this organization (the account itself is untouched). */
    public function changeStatus(User $user, string $status): User
    {
        if ($user->id === $this->tenancy->tenant()->userId()) {
            throw ApiException::unprocessable('CANNOT_CHANGE_OWN_STATUS', 'Anda tidak dapat mengubah status akun Anda sendiri.');
        }
        $member = $this->membership($user);
        if ($member->status === User::STATUS_DEACTIVATED) {
            throw ApiException::conflict('USER_DEACTIVATED', 'Akun yang sudah dinonaktifkan tidak dapat diaktifkan kembali.');
        }
        $this->roles->assertGrantable($this->permissionsOf($user));

        return DB::transaction(function () use ($user, $member, $status) {
            $orgId = $this->tenancy->organizationId();
            $this->adminGuard->lock($orgId);
            $before = $member->status;
            $member->forceFill(['status' => $status])->save();
            $this->adminGuard->assertHasActiveAdmin($orgId);

            // Tenant middleware re-checks the membership on every request; sessions are only
            // revoked outright when the account has no other organization to work in.
            if ($status !== User::STATUS_ACTIVE && $this->access->isExclusiveTo($user, $orgId)) {
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
            $orgId = $this->tenancy->organizationId();
            $this->adminGuard->lock($orgId);
            $before = $user->roles()->orderBy('code')->pluck('code')->all();
            $user->roles()->detach();
            $this->attachRoles($user, $newRoles->pluck('id')->all());
            $this->adminGuard->assertHasActiveAdmin($orgId);

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
        $this->assertExclusive($user);
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

    private function membership(User $user): OrganizationMember
    {
        if (! $user->relationLoaded('membership') || $user->membership === null) {
            $user->setRelation('membership', $user->membership()->firstOrFail());
        }

        return $user->membership;
    }

    /** Account-level changes would affect other organizations the user also belongs to. */
    private function assertExclusive(User $user): void
    {
        if (! $this->access->isExclusiveTo($user, $this->tenancy->organizationId())) {
            throw ApiException::unprocessable('SHARED_ACCOUNT',
                'Akun ini juga terdaftar di organisasi lain. Nama, email, telepon, dan password hanya dapat diubah oleh pemilik akun.');
        }
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
        $user->roles()->attach(array_fill_keys($roleIds, ['organization_id' => $this->tenancy->organizationId()]));
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
                'department' => Department::class,
                'location' => Location::class,
                default => throw ApiException::unprocessable('INVALID_SCOPE', 'Jenis data scope tidak valid.'),
            };
            if ($model !== null && ($ref === null || ! $model::query()->whereKey($ref)->exists())) {
                throw ApiException::unprocessable('INVALID_SCOPE', 'Referensi data scope tidak ditemukan.');
            }

            UserDataScope::grant($this->tenancy->organizationId(), $user->id, $type, $ref);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(array $data): void
    {
        if (! empty($data['home_department_id']) && ! Department::query()->whereKey($data['home_department_id'])->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Departemen tidak ditemukan.');
        }
    }

    /** @return list<string> */
    private function permissionsOf(User $user): array
    {
        return $this->permissions->tenantPermissions($user, $this->tenancy->organizationId());
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [...$user->only(self::ACCOUNT_FIELDS), ...$this->membership($user)->only([...OrganizationMember::PROFILE_FIELDS, 'status'])];
    }

    /** @return list<string> */
    private function scopeSnapshot(User $user): array
    {
        return UserDataScope::query()->where('user_id', $user->id)->get()
            ->map(fn (UserDataScope $s) => $s->scope_type.($s->refId() ? ':'.$s->refId() : ''))
            ->sort()->values()->all();
    }
}
