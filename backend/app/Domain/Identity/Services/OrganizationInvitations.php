<?php

namespace App\Domain\Identity\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 */
final class OrganizationInvitations
{
    /** What an administrator needs to invite (same as adding members before invitations). */
    public const MANAGE_PERMISSIONS = ['user.create', 'role.manage'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly UserManagement $users,
        private readonly OrganizationAccess $access,
        private readonly AssetScope $assetScope,
    ) {}

    /**
     * Invites an email address to the current organization and sends the invitation email.
     *
     * @param  array<string, mixed>  $profile  membership fields (OrganizationMember::PROFILE_FIELDS)
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function create(string $email, array $profile, array $roleIds, array $scopes): OrganizationInvitation
    {
        $email = mb_strtolower(trim($email));
        $this->assertGrantable($profile, $roleIds, $scopes);
        $this->assertNotMember($email);

        return DB::transaction(function () use ($email, $profile, $roleIds, $scopes) {
            // A pending invitation past its expiry no longer blocks a new one.
            OrganizationInvitation::query()->where('email', $email)->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where('expires_at', '<=', now())->update(['status' => OrganizationInvitation::STATUS_EXPIRED, 'updated_at' => now()]);
            if (OrganizationInvitation::query()->where('email', $email)->where('status', OrganizationInvitation::STATUS_PENDING)->exists()) {
                throw ApiException::conflict('INVITATION_PENDING', 'Undangan untuk email ini masih menunggu jawaban. Kirim ulang undangan tersebut bila perlu.');
            }

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
                'status' => OrganizationInvitation::STATUS_PENDING,
            ]);
            $token = $this->issueToken($invitation);
            $invitation->save();

            $this->audit->record('invitation.created', $invitation, after: $this->snapshot($invitation));
            $this->sendAfterCommit($invitation, $token);

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
            // Whoever resends now vouches for the access requested (validated above).
            $locked->forceFill(['status' => OrganizationInvitation::STATUS_PENDING, 'invited_by' => $this->tenancy->tenant()->userId()]);
            $token = $this->issueToken($locked);
            $locked->save();

            $this->audit->record('invitation.resent', $locked, after: ['expires_at' => $locked->expires_at->toIso8601String(), 'send_count' => $locked->send_count]);
            $this->sendAfterCommit($locked, $token);

            return $locked;
        });
    }

    public function revoke(OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->assertReopenable($invitation);

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
     * What the holder of a token is invited to. Only an open invitation is described.
     *
     * @return array{invitation: OrganizationInvitation, organization: Organization, inviter: User, roles: Collection<int, Role>}
     */
    public function inspect(string $token): array
    {
        $invitation = $this->findByToken($token);
        $this->assertOpen($invitation);

        return $this->tenancy->runAsSystem(fn () => [
            'invitation' => $invitation,
            'organization' => Organization::query()->findOrFail($invitation->organization_id),
            'inviter' => User::query()->findOrFail($invitation->invited_by),
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
    public function registerAndAccept(string $token, string $name, string $password): array
    {
        $invitation = $this->findByToken($token);
        $this->assertOpen($invitation);
        if (User::query()->where('email', $invitation->email)->exists()) {
            throw ApiException::conflict('ACCOUNT_EXISTS', 'Email ini sudah memiliki akun. Masuk dengan akun tersebut untuk menerima undangan.');
        }

        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            $user->forceFill([
                'user_type' => User::TYPE_TENANT,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ])->save();
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
            $this->audit->record('invitation.accepted', $locked, after: ['status' => OrganizationInvitation::STATUS_ACCEPTED, 'accepted_user_id' => $user->id], actor: $user);

            return ['organization' => $organization, 'invitation' => $locked];
        }));
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
        $actorScope = $this->tenancy->tenant()->scope();
        if ($actorScope->organizationWide) {
            return;
        }
        foreach ($scopes as $scope) {
            $ref = $scope['ref_id'] ?? null;
            $allowed = match ($scope['scope_type']) {
                'department' => in_array($ref, $actorScope->departmentIds, true),
                'location' => $this->assetScope->allowsLocation($ref),
                default => false,
            };
            if (! $allowed) {
                throw new ApiException('SCOPE_ESCALATION', 'Anda tidak dapat memberikan cakupan data di luar cakupan Anda sendiri.', 403);
            }
        }
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
    private function sendAfterCommit(OrganizationInvitation $invitation, string $token): void
    {
        $organization = $this->tenancy->tenant()->organization;
        $inviter = $this->tenancy->tenant()->user;
        DB::afterCommit(fn () => Notification::route('mail', $invitation->email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            inviterName: $inviter->name,
            token: $token,
            expiresAt: $invitation->expires_at->copy()->timezone($organization->timezone),
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
