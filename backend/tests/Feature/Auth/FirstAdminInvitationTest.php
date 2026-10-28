<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The first administrator's side of an organization created on the platform console: access
 * exists only after the owner of the invited address accepted with a verified account, or
 * signed up through the invitation.
 */
class FirstAdminInvitationTest extends TestCase
{
    private User $platform;

    private Organization $alpha;

    /** Existing account (administrator of ALPHA) with a verified email address. */
    private User $existing;

    private Organization $gamma;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:5173');
        ['organization' => $this->alpha, 'admin' => $this->existing] = $this->createOrganization('ALPHA');
        $this->existing->forceFill(['email_verified_at' => now()])->save();
        $this->platform = $this->createPlatformAdmin();
    }

    public function test_existing_verified_account_accepts_and_becomes_the_first_administrator(): void
    {
        $token = $this->createGamma($this->existing->email);
        $this->loginAs($this->existing);

        $this->postJson('/api/auth/invitation/inspect', ['token' => $token])->assertOk()
            ->assertJsonPath('data.organization.code', 'GAMMA')
            ->assertJsonPath('data.invited_by', 'Super Admin Asset Tracker')
            ->assertJsonPath('data.roles.0.code', 'ADMIN');

        // Extra input cannot redirect the invitation to another organization.
        $this->postJson('/api/auth/invitation/accept', ['token' => $token, 'organization_id' => $this->alpha->id])->assertOk()
            ->assertJsonPath('data.organization.id', $this->gamma->id);

        $member = OrganizationMember::query()->where('organization_id', $this->gamma->id)->where('user_id', $this->existing->id)->sole();
        $this->assertSame('active', $member->status);
        $this->assertSame(['ADMIN'], $this->existing->roles()->wherePivot('organization_id', $this->gamma->id)->pluck('code')->all());
        $this->assertSame(['organization'], UserDataScope::query()->where('organization_id', $this->gamma->id)->where('user_id', $this->existing->id)->pluck('scope_type')->all());
        // Nothing changed in ALPHA, and the account itself is untouched.
        $this->assertSame(['ADMIN'], $this->existing->roles()->wherePivot('organization_id', $this->alpha->id)->pluck('code')->all());
        $this->assertTrue(Hash::check(self::PASSWORD, $this->existing->fresh()->password));
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->gamma->id], $this->app->make(OrganizationAccess::class)->accessible($this->existing)->pluck('id')->all());

        $invitation = OrganizationInvitation::query()->where('organization_id', $this->gamma->id)->sole();
        $this->assertSame('accepted', $invitation->status);
        $this->assertSame($this->existing->id, $invitation->accepted_user_id);

        // The new administrator can work in GAMMA and administer it.
        $this->putJson('/api/auth/organization', ['organization_id' => $this->gamma->id])->assertOk();
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.code', 'GAMMA');
        $this->getJson('/api/invitations')->assertOk()->assertJsonPath('data.0.invited_by', null);

        $added = AuditLog::query()->where('action', 'user.member_added')->sole();
        $this->assertSame($this->gamma->id, $added->organization_id);
        $this->assertSame($this->existing->id, $added->actor_user_id);
        $this->assertSame($invitation->id, $added->metadata['invitation_id']);
        $this->assertSame($this->platform->id, $added->metadata['invited_by_platform_user_id']);
        $this->assertSame(['ADMIN'], $added->after['roles']);
        $accepted = AuditLog::query()->where('action', 'invitation.accepted')->sole();
        $this->assertSame($this->gamma->id, $accepted->organization_id);
        $this->assertSame($this->existing->id, $accepted->actor_user_id);
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->toJson());

        // Used once; and the platform cannot invite anybody else into the organization now.
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 409, 'INVITATION_USED');
        $this->loginAs($this->platform);
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation", ['email' => 'lain@luar.test']), 409, 'ORGANIZATION_HAS_MEMBERS');
        $this->getJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation")->assertOk()
            ->assertJsonPath('data.has_members', true)->assertJsonPath('data.invitation.status', 'accepted');
    }

    public function test_unverified_email_cannot_accept(): void
    {
        $this->existing->forceFill(['email_verified_at' => null])->save();
        $token = $this->createGamma($this->existing->email);
        $this->loginAs($this->existing);

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'EMAIL_NOT_VERIFIED');
        $this->assertNoAccess($this->existing);
        $this->assertSame('pending', OrganizationInvitation::query()->where('organization_id', $this->gamma->id)->sole()->status);
    }

    public function test_another_account_cannot_accept_and_a_session_is_required(): void
    {
        $token = $this->createGamma($this->existing->email);

        $this->beGuest();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 401, 'UNAUTHENTICATED');

        $other = $this->createTenantUser($this->alpha, ['SUPERVISOR']);
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->loginAs($other);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'INVITATION_EMAIL_MISMATCH');

        $this->assertNoAccess($other);
        $this->assertNoAccess($this->existing);
    }

    public function test_a_platform_account_cannot_accept(): void
    {
        $token = $this->createGamma($this->platform->email);
        $this->loginAs($this->platform);

        $this->postJson('/api/auth/invitation/accept', ['token' => $token])->assertForbidden();
        $this->assertFalse(OrganizationMember::query()->where('organization_id', $this->gamma->id)->exists());
    }

    public function test_expired_revoked_and_replaced_invitations_are_rejected(): void
    {
        $expired = $this->createGamma($this->existing->email);
        $this->travel(73)->hours();
        $this->loginAs($this->existing);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $expired]), 410, 'INVITATION_EXPIRED');

        // The platform resends the expired invitation: only the new link works.
        $this->loginAs($this->platform);
        $this->postJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation/resend")->assertOk()->assertJsonPath('data.status', 'pending');
        $fresh = $this->sentInvitationToken($this->existing->email, last: true);

        $this->postJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation/revoke")->assertOk();
        $this->loginAs($this->existing);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $expired]), 404, 'INVITATION_INVALID');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $fresh]), 410, 'INVITATION_REVOKED');

        // Replaced by an invitation to another address.
        $this->loginAs($this->platform);
        $this->postJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation", ['email' => $this->existing->email])->assertCreated();
        $replaced = $this->sentInvitationToken($this->existing->email, last: true);
        $this->postJson("/api/platform/organizations/{$this->gamma->id}/admin-invitation", ['email' => 'pengganti@luar.test'])->assertCreated();
        $this->loginAs($this->existing);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $replaced]), 410, 'INVITATION_REVOKED');

        $this->assertNoAccess($this->existing);
    }

    /** A token only ever gives access to the organization it was issued for. */
    public function test_invitation_is_bound_to_its_organization(): void
    {
        $gammaToken = $this->createGamma($this->existing->email);
        $this->loginAs($this->platform);
        $this->postJson('/api/platform/organizations', ['code' => 'delta', 'name' => 'PT Delta', 'admin' => ['email' => 'lain@luar.test'], 'license' => ['expires_at' => now()->addYear()->toIso8601String()]])->assertCreated();
        $delta = Organization::query()->where('code', 'DELTA')->sole();

        $this->loginAs($this->existing);
        $this->postJson('/api/auth/invitation/accept', ['token' => $gammaToken])->assertOk();

        $this->assertFalse(OrganizationMember::query()->where('organization_id', $delta->id)->where('user_id', $this->existing->id)->exists());
        $this->assertFalse(DB::table('user_roles')->where('organization_id', $delta->id)->where('user_id', $this->existing->id)->exists());
        $this->assertErrorCode($this->putJson('/api/auth/organization', ['organization_id' => $delta->id]), 403, 'ORGANIZATION_ACCESS_DENIED');
        $this->assertSame('pending', OrganizationInvitation::query()->where('organization_id', $delta->id)->sole()->status);
    }

    public function test_new_address_signs_up_through_the_invitation_verified_and_joins_as_administrator(): void
    {
        $token = $this->createGamma('baru@gamma.test');
        $this->assertFalse(User::query()->where('email', 'baru@gamma.test')->exists());
        $this->beGuest();

        $this->postJson('/api/auth/invitation/register', $this->signUp($token))->assertCreated()
            ->assertJsonPath('data.organization.id', $this->gamma->id);

        $user = User::query()->where('email', 'baru@gamma.test')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Rahasia-Kuat-2026', $user->password));
        $this->assertSame(['ADMIN'], $user->roles()->pluck('code')->all());
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.code', 'GAMMA');
        foreach (['auth.registered', 'auth.email_verified', 'user.member_added', 'invitation.accepted'] as $action) {
            $log = AuditLog::query()->where('action', $action)->where('actor_user_id', $user->id)->sole();
            $this->assertSame($this->gamma->id, $log->organization_id, $action);
        }

        $this->beGuest();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $this->signUp($token)), 409, 'INVITATION_USED');
    }

    public function test_sign_up_is_refused_for_an_address_that_has_an_account(): void
    {
        $token = $this->createGamma($this->existing->email);
        $this->beGuest();

        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $this->signUp($token)), 409, 'ACCOUNT_EXISTS');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->existing->fresh()->password));
        $this->assertNoAccess($this->existing);
    }

    public function test_platform_inviter_is_checked_again_when_accepting(): void
    {
        $token = $this->createGamma($this->existing->email);
        DB::table('platform_user_roles')->where('user_id', $this->platform->id)->delete();
        $this->loginAs($this->existing);

        $response = $this->postJson('/api/auth/invitation/accept', ['token' => $token]);
        $this->assertErrorCode($response, 410, 'INVITATION_UNAVAILABLE');
        $response->assertJsonPath('error.details.reason', 'inviter_lacks_permission');

        $this->platform->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 410, 'INVITATION_UNAVAILABLE');
        $this->assertNoAccess($this->existing);
    }

    public function test_suspended_organization_cannot_be_joined(): void
    {
        $token = $this->createGamma($this->existing->email);
        $this->gamma->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();
        $this->loginAs($this->existing);

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 410, 'INVITATION_UNAVAILABLE');
        $this->assertNoAccess($this->existing);
    }

    /** Defense in depth: even a pending platform invitation cannot add an administrator once somebody is a member. */
    public function test_acceptance_is_refused_once_the_organization_has_a_member(): void
    {
        $token = $this->createGamma($this->existing->email);
        $this->addMembership($this->createTenantUser($this->alpha), $this->gamma, 'ADMIN');
        $this->loginAs($this->existing);

        $response = $this->postJson('/api/auth/invitation/accept', ['token' => $token]);
        $this->assertErrorCode($response, 410, 'INVITATION_UNAVAILABLE');
        $response->assertJsonPath('error.details.reason', 'organization_has_members');
        $this->assertNoAccess($this->existing);
    }

    public function test_a_failure_while_accepting_leaves_no_partial_membership(): void
    {
        $token = $this->createGamma($this->existing->email);
        $this->loginAs($this->existing);
        // The data scope is written after the membership and the role: everything must roll back.
        UserDataScope::creating(fn () => throw new RuntimeException('simulated failure'));

        $this->postJson('/api/auth/invitation/accept', ['token' => $token])->assertStatus(500);

        $this->assertNoAccess($this->existing);
        $this->assertSame('pending', OrganizationInvitation::query()->where('organization_id', $this->gamma->id)->sole()->status);
        $this->assertFalse(AuditLog::query()->whereIn('action', ['user.member_added', 'invitation.accepted'])->exists());
    }

    public function test_platform_invitations_stay_inside_their_organization(): void
    {
        $this->createGamma('baru@gamma.test');

        // Another organization's administrator sees none of them, through the API or the database.
        $this->loginAs($this->existing);
        $this->getJson('/api/invitations')->assertOk()->assertJsonCount(0, 'data');
        $visible = $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->existing),
            fn () => DB::table('organization_invitations')->where('organization_id', $this->gamma->id)->count());
        $this->assertSame(0, $visible);
        $this->assertSame(1, DB::table('organization_invitations')->where('organization_id', $this->gamma->id)->count());
    }

    /** Creates GAMMA on the console with $email as invited administrator; returns the emailed token. */
    private function createGamma(string $email): string
    {
        $this->loginAs($this->platform);
        $this->postJson('/api/platform/organizations', ['code' => 'gamma', 'name' => 'PT Gamma', 'admin' => ['email' => $email], 'license' => ['expires_at' => now()->addYear()->toIso8601String()]])->assertCreated();
        $this->gamma = Organization::query()->where('code', 'GAMMA')->sole();

        return $this->sentInvitationToken($email, last: true);
    }

    private function loginAs(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    private function beGuest(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /** @return array<string, string> */
    private function signUp(string $token): array
    {
        return ['token' => $token, 'name' => 'Rina Baru', 'password' => 'Rahasia-Kuat-2026', 'password_confirmation' => 'Rahasia-Kuat-2026'];
    }

    private function assertNoAccess(User $user): void
    {
        $this->assertFalse(OrganizationMember::query()->where('organization_id', $this->gamma->id)->where('user_id', $user->id)->exists());
        $this->assertFalse(DB::table('user_roles')->where('organization_id', $this->gamma->id)->where('user_id', $user->id)->exists());
        $this->assertFalse(DB::table('user_data_scopes')->where('organization_id', $this->gamma->id)->where('user_id', $user->id)->exists());
    }
}
