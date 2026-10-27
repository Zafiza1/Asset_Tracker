<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Authorization\Services\DataScopeGuard;
use App\Domain\Authorization\Services\OrgAdminGuard;
use App\Domain\Authorization\Services\RoleManagement;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Services\Licensing;
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
 *
 * Nobody is added to an organization directly: administrators send invitations
 * (OrganizationInvitations), and the membership is created by addMember() only once the
 * owner of the (verified) email address accepted one.
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
        private readonly DataScopeGuard $scopeGuard,
        private readonly Licensing $licensing,
    ) {}

    /**
     * Checks that the acting administrator may give this access, without writing anything:
     * every role is within the actor's own permissions and every reference belongs to this
     * organization. Used when an invitation is created and again when it is accepted.
     *
     * @param  array<string, mixed>  $profile  membership fields (OrganizationMember::PROFILE_FIELDS)
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function assertAssignable(array $profile, array $roleIds, array $scopes): void
    {
        $this->assertReferences($profile);
        $this->resolveAssignableRoles($roleIds);
        $this->normalizeScopes($scopes);
    }

    /**
     * Makes an existing account a member of this organization. Only for an invitation that
     * the account owner accepted (OrganizationInvitations::accept); the access is validated
     * against the administrator of the current tenant context, who issued the invitation.
     * The account itself (password, profile) is never touched.
     *
     * @param  array<string, mixed>  $profile  membership fields (OrganizationMember::PROFILE_FIELDS)
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     * @param  array<string, mixed>  $metadata  audit context (e.g. the invitation)
     * @param  User|null  $actor  who is recorded as acting (the accepting account), defaults to the logged-in user
     */
    public function addMember(User $user, array $profile, array $roleIds, array $scopes, array $metadata = [], ?User $actor = null): User
    {
        if (! $user->isTenantUser()) {
            throw ApiException::unprocessable('INVALID_ACCOUNT', 'Akun platform tidak dapat menjadi anggota organisasi.');
        }
        $this->assertReferences($profile);
        $roles = $this->resolveAssignableRoles($roleIds);
        if (OrganizationMember::query()->where('user_id', $user->id)->exists()) {
            throw ApiException::conflict('ALREADY_MEMBER', 'Akun dengan email ini sudah menjadi anggota organisasi.');
        }

        return DB::transaction(function () use ($user, $profile, $roles, $scopes, $metadata, $actor) {
            $member = new OrganizationMember(array_intersect_key($profile, array_flip(OrganizationMember::PROFILE_FIELDS)));
            $member->forceFill(['user_id' => $user->id, 'status' => User::STATUS_ACTIVE, 'created_by' => $this->tenancy->tenant()->userId()])->save();
            $user->setRelation('membership', $member);

            $this->attachRoles($user, $roles->pluck('id')->all());
            $this->replaceScopes($user, $scopes);

            $this->audit->record('user.member_added', $user, after: [
                ...$this->snapshot($user),
                'roles' => $roles->pluck('code')->sort()->values()->all(),
                'scopes' => $this->scopeSnapshot($user),
            ], metadata: $metadata, actor: $actor);

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        $this->assertReferences($data);
        $member = $this->membership($user);
        $account = array_intersect_key($data, array_flip(self::ACCOUNT_FIELDS));
        $profile = array_intersect_key($data, array_flip(OrganizationMember::PROFILE_FIELDS));
        $accountChanged = self::changes($account, $user->only(array_keys($account)));
        if ($accountChanged) {
            $this->assertExclusive($user);
        }
        // Changing the login data of a broader account would be a step towards taking it over,
        // and its job data (title, department) is not the actor's to manage either.
        if ($accountChanged || self::changes($profile, $member->only(array_keys($profile)))) {
            $this->assertManageable($user);
        }

        return DB::transaction(function () use ($user, $member, $data, $account) {
            $before = $this->snapshot($user);
            $user->fill($account);
            // A new address has not been proven by its owner yet.
            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null]);
            }
            $user->save();
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
        $this->assertManageable($user);

        return DB::transaction(function () use ($user, $member, $status) {
            $orgId = $this->tenancy->organizationId();
            $this->adminGuard->lock($orgId);
            $before = $member->status;
            // Only active members use a seat; suspending or deactivating frees it for somebody else.
            if ($status === User::STATUS_ACTIVE && $before !== User::STATUS_ACTIVE) {
                $this->licensing->assertSeats($orgId);
            }
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

    /**
     * Roles being removed must also be within the actor's own permissions, and the member's
     * scope within the actor's: a role given to a broader member would apply beyond the actor's scope.
     *
     * @param  list<string>  $roleIds
     */
    public function syncRoles(User $user, array $roleIds): User
    {
        $newRoles = $this->resolveAssignableRoles($roleIds);
        $this->assertManageable($user);

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

    /**
     * Nobody changes their own scope, and an actor limited to some locations/departments can
     * only manage members (and hand out scopes) within them; see assertManageable().
     *
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function syncScopes(User $user, array $scopes): User
    {
        if ($user->id === $this->tenancy->tenant()->userId()) {
            throw ApiException::unprocessable('CANNOT_CHANGE_OWN_SCOPE', 'Anda tidak dapat mengubah cakupan data Anda sendiri.');
        }
        $this->assertManageable($user);
        $this->normalizeScopes($scopes);
        $this->scopeGuard->assertGrantable($scopes);

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
        $this->assertManageable($user);

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
        $normalized = $this->normalizeScopes($scopes);
        UserDataScope::query()->where('user_id', $user->id)->delete();

        foreach ($normalized as [$type, $ref]) {
            UserDataScope::grant($this->tenancy->organizationId(), $user->id, $type, $ref);
        }
    }

    /**
     * Distinct [scope_type, ref_id] pairs; every reference must exist in this organization.
     *
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     * @return list<array{0: string, 1: string|null}>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $type = $scope['scope_type'];
            $ref = $type === 'organization' ? null : ($scope['ref_id'] ?? null);
            if (isset($normalized[$type.'|'.$ref])) {
                continue;
            }

            $model = match ($type) {
                'organization' => null,
                'department' => Department::class,
                'location' => Location::class,
                default => throw ApiException::unprocessable('INVALID_SCOPE', 'Jenis data scope tidak valid.'),
            };
            if ($model !== null && ($ref === null || ! $model::query()->whereKey($ref)->exists())) {
                throw ApiException::unprocessable('INVALID_SCOPE', 'Referensi data scope tidak ditemukan.');
            }
            $normalized[$type.'|'.$ref] = [$type, $ref];
        }

        return array_values($normalized);
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(array $data): void
    {
        if (! empty($data['home_department_id']) && ! Department::query()->whereKey($data['home_department_id'])->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Departemen tidak ditemukan.');
        }
    }

    /**
     * The actor may act on this member's access only if the member is not more powerful:
     * every permission and every scope entry of the member is within the actor's own.
     */
    private function assertManageable(User $user): void
    {
        $this->roles->assertGrantable($this->permissionsOf($user));
        $this->scopeGuard->assertCoversUser($user);
    }

    /**
     * @param  array<string, mixed>  $requested
     * @param  array<string, mixed>  $current
     */
    private static function changes(array $requested, array $current): bool
    {
        return array_diff_assoc(array_map('strval', $requested), array_map('strval', $current)) !== [];
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
