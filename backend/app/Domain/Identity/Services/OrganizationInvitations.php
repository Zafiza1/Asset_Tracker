<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Authorization\Services\DataScopeGuard;
use App\Domain\Authorization\Services\RoleManagement;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Lifecycle of organization invitations, the only way into an organization.
 *
 *  - create/resend/revoke run in the inviting organization's tenant context, so RLS and the
 *    global scope keep every invitation inside its organization. The administrator may only
 *    request roles within their own permissions and data scopes within their own scope.
 *    Responses never depend on whether the email already has an account.
 *  - The token (256 random bits) is only in the email; the database keeps its SHA-256 hash.
 *    It is valid for a limited time and for one acceptance. Resending replaces it.
 *  - accept: the logged-in owner of the invited, verified email address accepts. The
 *    invitation row is locked, the inviter's rights are checked again, and the membership is
 *    created by UserManagement in the organization's tenant context, as the inviter.
 *  - registerAndAccept: an address without an account signs up through the invitation. The
 *    token was delivered only to that mailbox, so using it proves the address (verified).
 *  - inviteFirstAdmin/resendFirstAdmin/revokeFirstAdmin: the platform console invites the first
 *    Organization Administrator of an organization it created, in the platform context. Only
 *    while the organization has no member at all, and only for its locked ORG_ADMIN role with
 *    organization-wide scope; the platform never adds anybody to a running organization.
 *    Accepting re-checks the platform account and that the organization still has no member,
 *    then creates the membership in the organization's tenant context, as the accepting account.
 */
final class OrganizationInvitations
{
    /** What an administrator needs to invite (same as adding members before invitations). */
    public const MANAGE_PERMISSIONS = ['user.create', 'role.manage'];

    /** What a platform account needs to invite an organization's first administrator. */
    public const PLATFORM_MANAGE_PERMISSION = 'platform.organization.manage';

    /** How the invitation names a platform inviter (platform staff stay anonymous to invitees). */
    public const PLATFORM_INVITER_NAME = 'Administrator platform Asset Tracker';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly UserManagement $users,
        private readonly OrganizationAccess $access,
        private readonly DataScopeGuard $scopeGuard,
        private readonly PermissionResolver $permissions,
        private readonly RoleManagement $roles,
        private readonly Licensing $licensing,
        private readonly LicenseCodeService $licenseCodes,
    ) {}

    /**
     * Invites an email address to the current organization and sends the invitation email.
     *
     * @param  array<string, mixed>  $profile  membership fields (OrganizationMember::PROFILE_FIELDS)
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     * @param  string|null  $licenseCodeId  Optional license code to assign to the invitation
     */
    public function create(string $email, array $profile, array $roleIds, array $scopes, ?string $licenseCodeId = null): OrganizationInvitation
    {
        $email = mb_strtolower(trim($email));
        $this->assertGrantable($profile, $roleIds, $scopes);
        $this->assertNotMember($email);

        // Validate and reserve license code if provided
        if ($licenseCodeId !== null) {
            $licenseCode = LicenseCode::query()
                ->where('id', $licenseCodeId)
                ->where('organization_id', $this->tenancy->organizationId())
                ->where('status', LicenseCode::STATUS_AVAILABLE)
                ->first();

            if (! $licenseCode) {
                throw ApiException::conflict('INVALID_LICENSE_CODE', 'Kode lisensi tidak valid atau tidak tersedia.');
            }
        }

        return DB::transaction(function () use ($email, $profile, $roleIds, $scopes, $licenseCodeId) {
            // A pending invitation past its expiry no longer blocks a new one.
            OrganizationInvitation::query()->where('email', $email)->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where('expires_at', '<=', now())->update(['status' => OrganizationInvitation::STATUS_EXPIRED, 'updated_at' => now()]);
            if (OrganizationInvitation::query()->where('email', $email)->where('status', OrganizationInvitation::STATUS_PENDING)->exists()) {
                throw ApiException::conflict('INVITATION_PENDING', 'Undangan untuk email ini masih menunggu jawaban. Kirim ulang undangan tersebut bila perlu.');
            }
            if (! empty($profile['employee_number']) && OrganizationInvitation::query()->where('employee_number', $profile['employee_number'])
                ->where('status', OrganizationInvitation::STATUS_PENDING)->exists()) {
                throw ApiException::conflict('EMPLOYEE_NUMBER_TAKEN', 'Nomor karyawan ini sudah dipakai pada undangan lain yang masih menunggu jawaban.');
            }
            // A pending invitation holds a seat until it is accepted, revoked or expires.
            $this->licensing->assertSeats($this->tenancy->organizationId());

            $invitation = new OrganizationInvitation;
            $invitation->forceFill([
                ...array_intersect_key($profile, array_flip(OrganizationMember::PROFILE_FIELDS)),
                'email' => $email,
                'role_ids' => array_values(array_unique($roleIds)),
                'scopes' => array_map(fn (array $s) => [
                    'scope_type' => $s['scope_type'],
                    'ref_id' => $s['scope_type'] === 'organization' ? null : ($s['ref_id'] ?? null),
                ], $scopes),
                'invited_by' => $this->tenancy->tenant()->userId(),
                'license_code_id' => $licenseCodeId,
                'status' => OrganizationInvitation::STATUS_PENDING,
            ]);
            $token = $this->issueToken($invitation);
            $invitation->save();

            // Assign license code to the invitation's user when they accept
            // This will be handled in the accept/complete method

            $this->audit->record('invitation.created', $invitation, after: $this->snapshot($invitation));
            $this->sendAfterCommit($invitation, $token, $this->tenancy->tenant()->organization, $this->tenancy->tenant()->user->name);

            return $invitation;
        });
    }

    /** Sends a fresh token (the previous one stops working) and restarts the validity period. */
    public function resend(OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->assertReopenable($invitation);
        $this->assertGrantable($this->profileOf($invitation), $invitation->role_ids, $invitation->scopes);
        $this->assertNotMember($invitation->email);

        return DB::transaction(function () use ($invitation) {
            $locked = $this->lock($invitation);
            $this->assertReopenable($locked);
            // An expired invitation had freed its seat; reopening takes one again.
            if ($locked->effectiveStatus() !== OrganizationInvitation::STATUS_PENDING) {
                $this->licensing->assertSeats($this->tenancy->organizationId());
            }
            // Whoever resends now vouches for the access requested (validated above).
            $locked->forceFill([
                'status' => OrganizationInvitation::STATUS_PENDING,
                'invited_by' => $this->tenancy->tenant()->userId(),
                'invited_by_platform_user_id' => null,
            ]);
            $token = $this->issueToken($locked);
            $locked->save();

            $this->audit->record('invitation.resent', $locked, after: ['expires_at' => $locked->expires_at->toIso8601String(), 'send_count' => $locked->send_count]);
            $this->sendAfterCommit($locked, $token, $this->tenancy->tenant()->organization, $this->tenancy->tenant()->user->name);

            return $locked;
        });
    }

    /**
     * Withdrawing access is still acting on it: only someone who could have granted what the
     * invitation offers may revoke it, so a scoped administrator cannot cancel, say, the
     * invitation of an organization-wide administrator. Roles deleted since are ignored.
     */
    public function revoke(OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->assertReopenable($invitation);
        foreach (Role::query()->whereIn('id', $invitation->role_ids)->get() as $role) {
            $this->roles->assertEditable($role);
        }
        $this->assertWithinActorScope($invitation->scopes);

        return DB::transaction(function () use ($invitation) {
            $locked = $this->lock($invitation);
            $this->assertReopenable($locked);
            $before = $locked->effectiveStatus();
            $locked->forceFill([
                'status' => OrganizationInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $this->tenancy->tenant()->userId(),
            ])->save();

            $this->audit->record('invitation.revoked', $locked, before: ['status' => $before], after: ['status' => OrganizationInvitation::STATUS_REVOKED]);

            return $locked;
        });
    }

    /**
     * Invites the first Organization Administrator of an organization created on the platform
     * console. An open platform invitation of the organization is revoked first: one candidate
     * at a time. The result does not depend on whether the address has an account; an address
     * that can never accept (a platform account) is only noted in the server log.
     */
    public function inviteFirstAdmin(Organization $organization, string $email): OrganizationInvitation
    {
        $platformUser = $this->platformManager();
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($organization, $email, $platformUser) {
            $org = $this->lockAwaitingAdmin($organization);
            $open = OrganizationInvitation::query()->where('organization_id', $org->id)
                ->whereNotNull('invited_by_platform_user_id')->where('status', OrganizationInvitation::STATUS_PENDING)->get();
            foreach ($open as $previous) {
                $this->revokeAsPlatform($previous, 'replaced');
            }
            $this->licensing->assertSeats($org->id);

            $roleId = Role::query()->where('organization_id', $org->id)->where('template_code', RoleTemplates::ORG_ADMIN)->where('is_locked', true)->value('id')
                ?? throw ApiException::conflict('ORG_ADMIN_ROLE_MISSING', 'Role Administrator Organisasi tidak ditemukan.');

            $invitation = new OrganizationInvitation;
            $invitation->forceFill([
                'organization_id' => $org->id,
                'email' => $email,
                'role_ids' => [$roleId],
                'scopes' => [['scope_type' => 'organization', 'ref_id' => null]],
                'invited_by_platform_user_id' => $platformUser->id,
                'status' => OrganizationInvitation::STATUS_PENDING,
            ]);
            $token = $this->issueToken($invitation);
            $invitation->save();

            $this->audit->record('invitation.created', $invitation, after: [
                'email' => $email,
                'roles' => [RoleTemplates::ORG_ADMIN],
                'scopes' => ['organization'],
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ], metadata: ['source' => 'platform_console', 'first_admin' => true], organizationId: $org->id);
            $this->sendAfterCommit($invitation, $token, $org, self::PLATFORM_INVITER_NAME);

            $account = User::query()->where('email', $email)->first();
            $context = ['organization_id' => $org->id, 'invitation_id' => $invitation->id];
            Log::info('First administrator invited from the platform console.', [...$context, 'existing_account' => $account !== null]);
            if ($account !== null && ! $account->isTenantUser()) {
                Log::warning('First administrator invitation addressed to a platform account; it cannot be accepted.', $context);
            }

            return $invitation;
        });
    }

    /** Sends a fresh token for the organization's latest platform invitation (pending or expired). */
    public function resendFirstAdmin(Organization $organization): OrganizationInvitation
    {
        $platformUser = $this->platformManager();

        return DB::transaction(function () use ($organization, $platformUser) {
            $org = $this->lockAwaitingAdmin($organization);
            $locked = $this->lock($this->reopenableFirstAdminInvitation($org));
            $this->assertReopenable($locked);
            if ($locked->effectiveStatus() !== OrganizationInvitation::STATUS_PENDING) {
                $this->licensing->assertSeats($org->id);
            }
            $locked->forceFill(['status' => OrganizationInvitation::STATUS_PENDING, 'invited_by_platform_user_id' => $platformUser->id]);
            $token = $this->issueToken($locked);
            $locked->save();

            $this->audit->record('invitation.resent', $locked, after: ['expires_at' => $locked->expires_at->toIso8601String(), 'send_count' => $locked->send_count],
                metadata: ['source' => 'platform_console', 'first_admin' => true], organizationId: $org->id);
            $this->sendAfterCommit($locked, $token, $org, self::PLATFORM_INVITER_NAME);

            return $locked;
        });
    }

    public function revokeFirstAdmin(Organization $organization): OrganizationInvitation
    {
        $this->platformManager();

        return DB::transaction(function () use ($organization) {
            $org = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            return $this->revokeAsPlatform($this->reopenableFirstAdminInvitation($org), 'revoked');
        });
    }

    /** The organization's most recent platform invitation (any status), for the platform console. */
    public function firstAdminInvitation(Organization $organization): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()->where('organization_id', $organization->id)
            ->whereNotNull('invited_by_platform_user_id')->latest('created_at')->latest('id')->first();
    }

    /** Whether the organization has any member yet (an accepted first administrator, at least). */
    public function hasMembers(Organization $organization): bool
    {
        return OrganizationMember::query()->where('organization_id', $organization->id)->exists();
    }

    /**
     * What the holder of a token is invited to. Only an open invitation is described.
     * A platform invitation has no inviter account to show (see PLATFORM_INVITER_NAME).
     *
     * @return array{invitation: OrganizationInvitation, organization: Organization, inviter: User|null, roles: Collection<int, Role>}
     */
    public function inspect(string $token): array
    {
        $invitation = $this->findByToken($token);
        $this->assertOpen($invitation);

        return $this->tenancy->runAsSystem(fn () => [
            'invitation' => $invitation,
            'organization' => Organization::query()->findOrFail($invitation->organization_id),
            'inviter' => $invitation->isPlatformIssued() ? null : User::query()->findOrFail($invitation->invited_by),
            'roles' => Role::query()->where('organization_id', $invitation->organization_id)->whereIn('id', $invitation->role_ids)->orderBy('name')->get(),
        ]);
    }

    /**
     * The logged-in account accepts: it must be the invited, verified email address.
     *
     * @return array{organization: Organization, invitation: OrganizationInvitation}
     */
    public function accept(User $user, string $token): array
    {
        $invitation = $this->findByToken($token);
        $this->assertOpen($invitation);

        if (! $user->isTenantUser()) {
            throw ApiException::forbidden('TENANT_USER_REQUIRED', 'Akun platform tidak dapat bergabung dengan organisasi.');
        }
        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw ApiException::forbidden('INVITATION_EMAIL_MISMATCH', 'Undangan ini ditujukan untuk email lain. Masuk dengan akun yang sesuai dengan email undangan.');
        }
        if (! $user->hasVerifiedEmail()) {
            throw ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Verifikasi email Anda terlebih dahulu sebelum menerima undangan.');
        }

        return $this->complete($user, $invitation);
    }

    /**
     * Creates the account of an invited address that has none yet, and accepts the invitation,
     * in one transaction. Holding the token proves the address, so it starts verified.
     *
     * @return array{user: User, organization: Organization, invitation: OrganizationInvitation}
     */
    public function registerAndAccept(string $token, string $name, string $password, ?string $licenseCode): array
    {
        $invitation = $this->findByToken($token);
        $this->assertOpen($invitation);

        // License code is required: either from invitation or provided by user
        if ($invitation->license_code_id === null) {
            $licenseCode = trim($licenseCode ?? '');
            if ($licenseCode === '') {
                throw ApiException::unprocessable('LICENSE_CODE_REQUIRED', 'Kode lisensi wajib diisi untuk bergabung dengan organisasi ini.');
            }
            $validatedCode = $this->licenseCodes->validateCode($licenseCode, $invitation->organization_id);
            $invitation->license_code_id = $validatedCode->id;
        }

        // Accounts are only visible and created in the system context (RLS on users, SEC-07).
        if ($this->tenancy->runAsSystem(fn () => User::query()->where('email', $invitation->email)->exists())) {
            throw ApiException::conflict('ACCOUNT_EXISTS', 'Email ini sudah memiliki akun. Masuk dengan akun tersebut untuk menerima undangan.');
        }

        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            $this->tenancy->runAsSystem(fn () => $user->forceFill([
                'user_type' => User::TYPE_TENANT,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ])->save());
            // The new account is the actor of everything below (no session exists yet).
            Auth::guard('web')->setUser($user);

            $orgId = $invitation->organization_id;
            $this->audit->record('auth.registered', $user, metadata: ['via' => 'invitation', 'invitation_id' => $invitation->id], organizationId: $orgId, actor: $user);
            $this->audit->record('auth.email_verified', $user, metadata: ['method' => 'invitation', 'invitation_id' => $invitation->id], organizationId: $orgId, actor: $user);

            return ['user' => $user, ...$this->complete($user, $invitation)];
        });
    }

    /** Marks pending invitations past their expiry as expired (housekeeping; reads treat them as expired anyway). */
    public function expireStale(): int
    {
        return $this->tenancy->runAsSystem(fn () => OrganizationInvitation::query()
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => OrganizationInvitation::STATUS_EXPIRED, 'updated_at' => now()]));
    }

    /**
     * Attaches the requested roles (as a "roles" relation) for display. Runs in the tenant context.
     *
     * @param  iterable<OrganizationInvitation>  $invitations
     */
    public function withRoles(iterable $invitations): void
    {
        $invitations = collect($invitations);
        $roles = Role::query()->whereIn('id', $invitations->pluck('role_ids')->flatten()->unique()->values())->get()->keyBy('id');
        foreach ($invitations as $invitation) {
            $invitation->setRelation('roles', collect($invitation->role_ids)->map(fn (string $id) => $roles->get($id))->filter()->values());
        }
    }

    /**
     * Re-validates the invitation against its organization and inviter, then creates the
     * membership. Concurrent acceptances serialize on the row lock; the loser sees "used".
     *
     * @return array{organization: Organization, invitation: OrganizationInvitation}
     */
    private function complete(User $user, OrganizationInvitation $invitation): array
    {
        if ($invitation->isPlatformIssued()) {
            return $this->completeFirstAdmin($user, $invitation);
        }

        [$organization, $inviter] = $this->tenancy->runAsSystem(fn () => [
            Organization::query()->where('status', Organization::STATUS_ACTIVE)->find($invitation->organization_id),
            User::query()->find($invitation->invited_by),
        ]);
        // The inviter must still be an active member of an active organization.
        if ($organization === null || $inviter === null || $this->access->find($inviter, $organization->id) === null) {
            throw $this->unavailable('inviter_inactive');
        }

        return $this->tenancy->runAsTenant(new TenantContext($organization, $inviter), fn () => DB::transaction(function () use ($user, $invitation, $organization) {
            $locked = $this->lock($invitation);
            $this->assertOpen($locked);
            $this->assertSeatForInvitee($organization->id);
            try {
                if (! collect(self::MANAGE_PERMISSIONS)->every(fn (string $p) => $this->tenancy->tenant()->can($p))) {
                    throw $this->unavailable('inviter_lacks_permission');
                }
                $this->assertWithinActorScope($locked->scopes);
                $this->users->addMember($user, $this->profileOf($locked), $locked->role_ids, $locked->scopes,
                    metadata: ['invitation_id' => $locked->id, 'invited_by' => $locked->invited_by], actor: $user);
            } catch (ApiException $e) {
                throw in_array($e->errorCode, ['ALREADY_MEMBER', 'INVITATION_UNAVAILABLE'], true) ? $e : $this->unavailable(strtolower($e->errorCode));
            }

            $locked->forceFill([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            // Assign license code to the user if one was provided
            if ($locked->license_code_id !== null) {
                $this->licenseCodes->assign($locked->license_code_id, $user->id);
            }

            $this->audit->record('invitation.accepted', $locked, after: ['status' => OrganizationInvitation::STATUS_ACCEPTED, 'accepted_user_id' => $user->id], actor: $user);

            return ['organization' => $organization, 'invitation' => $locked];
        }));
    }

    /**
     * Accepts a platform invitation: the issuing platform account must still be active and allowed
     * to manage organizations, and the organization must still be active and without members.
     * Writes run in the organization's tenant context (RLS applies), with the organization row
     * locked so that two acceptances cannot both become its first administrator.
     *
     * @return array{organization: Organization, invitation: OrganizationInvitation}
     */
    private function completeFirstAdmin(User $user, OrganizationInvitation $invitation): array
    {
        [$organization, $inviter] = $this->tenancy->runAsSystem(fn () => [
            Organization::query()->where('status', Organization::STATUS_ACTIVE)->find($invitation->organization_id),
            User::query()->find($invitation->invited_by_platform_user_id),
        ]);
        if ($organization === null || $inviter === null || ! $inviter->isPlatformUser() || ! $inviter->isActive()) {
            throw $this->unavailable('inviter_inactive');
        }
        if (! in_array(self::PLATFORM_MANAGE_PERMISSION, $this->tenancy->runAsSystem(fn () => $this->permissions->platformPermissions($inviter)), true)) {
            throw $this->unavailable('inviter_lacks_permission');
        }

        return DB::transaction(function () use ($user, $invitation, $organization) {
            // The database accepts a tenant context only for an active member (SEC-07), which this
            // account is not yet: the checks and the membership run in the system context, scoped
            // to this organization explicitly, and everything after it in the tenant context.
            [$locked, $role] = $this->tenancy->runAsSystem(function () use ($user, $invitation, $organization) {
                $locked = $this->lock($invitation);
                $this->assertOpen($locked);
                Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
                if (OrganizationMember::query()->where('organization_id', $organization->id)->exists()) {
                    throw $this->unavailable('organization_has_members');
                }
                $this->assertSeatForInvitee($organization->id);
                $role = Role::query()->where('organization_id', $organization->id)
                    ->where('template_code', RoleTemplates::ORG_ADMIN)->where('is_locked', true)->first();
                $scopes = $locked->scopes;
                if ($role === null || $locked->role_ids !== [$role->id] || count($scopes) !== 1 || $scopes[0]['scope_type'] !== 'organization') {
                    throw $this->unavailable('invalid_access');
                }
                (new OrganizationMember)->forceFill(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => User::STATUS_ACTIVE])->save();

                return [$locked, $role];
            });

            return $this->tenancy->runAsTenant(new TenantContext($organization, $user), fn () => $this->grantFirstAdmin($user, $organization, $locked, $role));
        });
    }

    /** @return array{organization: Organization, invitation: OrganizationInvitation} */
    private function grantFirstAdmin(User $user, Organization $organization, OrganizationInvitation $locked, Role $role): array
    {
        $user->roles()->attach($role->id, ['organization_id' => $organization->id]);
        UserDataScope::grant($organization->id, $user->id, 'organization');

        $this->audit->record('user.member_added', $user, after: [
            'name' => $user->name,
            'email' => $user->email,
            'status' => User::STATUS_ACTIVE,
            'roles' => [RoleTemplates::ORG_ADMIN],
            'scopes' => ['organization'],
        ], metadata: ['invitation_id' => $locked->id, 'invited_by_platform_user_id' => $locked->invited_by_platform_user_id, 'first_admin' => true], actor: $user);

        $locked->forceFill([
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();
        $this->audit->record('invitation.accepted', $locked, after: ['status' => OrganizationInvitation::STATUS_ACCEPTED, 'accepted_user_id' => $user->id], actor: $user);

        return ['organization' => $organization, 'invitation' => $locked];
    }

    /** The platform account acting on the console; checked here as well as by the route. */
    private function platformManager(): User
    {
        $platform = $this->tenancy->platform();
        if ($platform === null || ! $this->tenancy->isUnrestricted() || ! $platform->can(self::PLATFORM_MANAGE_PERMISSION)) {
            throw ApiException::forbidden();
        }

        return $platform->user;
    }

    /**
     * Locks the organization row (serializing platform invitation changes and acceptance) and
     * checks it can still receive a first administrator: active, and nobody is a member yet.
     */
    private function lockAwaitingAdmin(Organization $organization): Organization
    {
        $org = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        if (! $org->isActive()) {
            throw ApiException::conflict('ORGANIZATION_INACTIVE', 'Organisasi tidak aktif. Aktifkan organisasi sebelum mengundang administrator.');
        }
        if ($this->hasMembers($org)) {
            throw ApiException::conflict('ORGANIZATION_HAS_MEMBERS', 'Organisasi ini sudah memiliki anggota. Pengguna baru diundang oleh administrator organisasi.');
        }

        return $org;
    }

    private function reopenableFirstAdminInvitation(Organization $organization): OrganizationInvitation
    {
        $invitation = $this->firstAdminInvitation($organization);
        if ($invitation === null || ! in_array($invitation->status, [OrganizationInvitation::STATUS_PENDING, OrganizationInvitation::STATUS_EXPIRED], true)) {
            throw ApiException::conflict('NO_OPEN_INVITATION', 'Tidak ada undangan administrator yang dapat dikirim ulang atau dicabut. Kirim undangan baru.');
        }

        return $invitation;
    }

    /** revoked_by references a member; a platform revocation is attributed in the audit trail instead. */
    private function revokeAsPlatform(OrganizationInvitation $invitation, string $reason): OrganizationInvitation
    {
        $locked = $this->lock($invitation);
        $this->assertReopenable($locked);
        $before = $locked->effectiveStatus();
        $locked->forceFill(['status' => OrganizationInvitation::STATUS_REVOKED, 'revoked_at' => now()])->save();

        $this->audit->record('invitation.revoked', $locked, before: ['status' => $before], after: ['status' => OrganizationInvitation::STATUS_REVOKED],
            metadata: ['source' => 'platform_console', 'first_admin' => true, 'reason' => $reason], organizationId: $locked->organization_id);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    private function assertGrantable(array $profile, array $roleIds, array $scopes): void
    {
        $this->users->assertAssignable($profile, $roleIds, $scopes);
        $this->assertWithinActorScope($scopes);
    }

    /**
     * An administrator limited to some locations/departments can only invite within them.
     *
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    private function assertWithinActorScope(array $scopes): void
    {
        $this->scopeGuard->assertGrantable($scopes);
    }

    /** Members of this organization (any status) are managed on their user page, not invited. */
    private function assertNotMember(string $email): void
    {
        $user = User::query()->where('email', $email)->first();
        if ($user !== null && OrganizationMember::query()->where('user_id', $user->id)->exists()) {
            throw ApiException::conflict('ALREADY_MEMBER', 'Akun dengan email ini sudah menjadi anggota organisasi.');
        }
    }

    /** The token is never stored; a lookup by hash is the only way to find an invitation from it. */
    private function findByToken(string $token): OrganizationInvitation
    {
        $invitation = $token === '' ? null : $this->tenancy->runAsSystem(
            fn () => OrganizationInvitation::query()->where('token_hash', OrganizationInvitation::hashToken($token))->first(),
        );

        return $invitation ?? throw new ApiException('INVITATION_INVALID', 'Tautan undangan tidak valid. Periksa kembali tautan pada email Anda.', 404);
    }

    private function assertOpen(OrganizationInvitation $invitation): void
    {
        match ($invitation->effectiveStatus()) {
            OrganizationInvitation::STATUS_PENDING => null,
            OrganizationInvitation::STATUS_ACCEPTED => throw ApiException::conflict('INVITATION_USED', 'Undangan ini sudah diterima dan tidak dapat dipakai lagi.'),
            OrganizationInvitation::STATUS_REVOKED => throw new ApiException('INVITATION_REVOKED', 'Undangan ini telah dibatalkan oleh administrator organisasi.', 410),
            default => throw new ApiException('INVITATION_EXPIRED', 'Undangan ini sudah kedaluwarsa. Minta administrator organisasi mengirim ulang undangan.', 410),
        };
    }

    /** Pending and expired invitations can be resent or revoked; accepted and revoked ones are final. */
    private function assertReopenable(OrganizationInvitation $invitation): void
    {
        if (in_array($invitation->status, [OrganizationInvitation::STATUS_ACCEPTED, OrganizationInvitation::STATUS_REVOKED], true)) {
            throw ApiException::conflict('INVITATION_CLOSED', 'Undangan yang sudah diterima atau dibatalkan tidak dapat diubah.');
        }
    }

    /**
     * The invitation's seat is already counted, but the license may have ended or shrunk since
     * it was sent. The invitation stays open: it works again once the organization has seats.
     */
    private function assertSeatForInvitee(string $organizationId): void
    {
        try {
            $this->licensing->assertSeats($organizationId, 0);
        } catch (ApiException $e) {
            throw ApiException::conflict('ORGANIZATION_NO_SEAT',
                'Organisasi ini sedang tidak memiliki seat lisensi untuk Anda. Hubungi administrator organisasi, lalu coba lagi.',
                ['reason' => strtolower($e->errorCode)]);
        }
    }

    private function unavailable(string $reason): ApiException
    {
        return new ApiException('INVITATION_UNAVAILABLE',
            'Undangan ini tidak dapat digunakan lagi. Minta administrator organisasi mengirim undangan baru.', 410, ['reason' => $reason]);
    }

    private function lock(OrganizationInvitation $invitation): OrganizationInvitation
    {
        return OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
    }

    /** Sets a new token hash and validity period; returns the token for the email only. */
    private function issueToken(OrganizationInvitation $invitation): string
    {
        $token = bin2hex(random_bytes(32));
        $invitation->forceFill([
            'token_hash' => OrganizationInvitation::hashToken($token),
            'expires_at' => now()->addHours((int) config('auth.invitations.expire_hours')),
            'last_sent_at' => now(),
            'send_count' => ($invitation->send_count ?? 0) + 1,
        ]);

        return $token;
    }

    /** Only after commit: a rolled-back invitation must not leave a mail with a dead link. */
    private function sendAfterCommit(OrganizationInvitation $invitation, string $token, Organization $organization, string $inviterName): void
    {
        $licenseCode = null;
        if ($invitation->license_code_id) {
            $licenseCode = LicenseCode::query()->where('id', $invitation->license_code_id)->value('code');
        }

        DB::afterCommit(fn () => Notification::route('mail', $invitation->email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            inviterName: $inviterName,
            token: $token,
            expiresAt: $invitation->expires_at->copy()->timezone($organization->timezone),
            licenseCode: $licenseCode,
        )));
    }

    /** @return array<string, mixed> */
    private function profileOf(OrganizationInvitation $invitation): array
    {
        return $invitation->only(OrganizationMember::PROFILE_FIELDS);
    }

    /** @return array<string, mixed> */
    private function snapshot(OrganizationInvitation $invitation): array
    {
        return [
            'email' => $invitation->email,
            'roles' => Role::query()->whereIn('id', $invitation->role_ids)->orderBy('code')->pluck('code')->all(),
            'scopes' => collect($invitation->scopes)->map(fn (array $s) => $s['scope_type'].(! empty($s['ref_id']) ? ':'.$s['ref_id'] : ''))->sort()->values()->all(),
            ...$this->profileOf($invitation),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
