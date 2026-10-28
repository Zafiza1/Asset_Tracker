<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The invitee's side: inspect, accept as an existing verified account, or sign up through the invitation. */
class InvitationAcceptanceTest extends TestCase
{
    private Organization $alpha;

    private User $admin;

    private Organization $beta;

    /** Existing account (administrator of BETA) with a verified email address. */
    private User $invitee;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:5173');
        ['organization' => $this->alpha, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        ['organization' => $this->beta, 'admin' => $this->invitee] = $this->createOrganization('BETA');
        $this->invitee->forceFill(['email_verified_at' => now()])->save();
    }

    public function test_existing_verified_account_accepts_and_gets_exactly_the_invited_access(): void
    {
        $location = $this->location();
        $token = $this->invite($this->invitee->email, 'SUPERVISOR', [['scope_type' => 'location', 'ref_id' => $location->id]], ['job_title' => 'Teknisi']);
        $this->loginAs($this->invitee);
        $this->putJson('/api/auth/organization', ['organization_id' => $this->beta->id])->assertOk();

        $this->postJson('/api/auth/invitation/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->alpha->id);

        $member = OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->invitee->id)->sole();
        $this->assertSame('active', $member->status);
        $this->assertSame('Teknisi', $member->job_title);
        $this->assertSame($this->admin->id, $member->created_by);
        $this->assertSame(['SUPERVISOR'], $this->invitee->roles()->wherePivot('organization_id', $this->alpha->id)->pluck('code')->all());
        $this->assertSame([['location', $location->id]], UserDataScope::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->invitee->id)
            ->get()->map(fn ($s) => [$s->scope_type, $s->refId()])->all());

        $invitation = OrganizationInvitation::query()->where('email', $this->invitee->email)->sole();
        $this->assertSame('accepted', $invitation->status);
        $this->assertSame($this->invitee->id, $invitation->accepted_user_id);
        $this->assertNotNull($invitation->accepted_at);

        // The account itself is untouched; the new organization joins the switcher, the active one stays.
        $this->assertTrue(Hash::check(self::PASSWORD, $this->invitee->fresh()->password));
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $this->app->make(OrganizationAccess::class)->accessible($this->invitee)->pluck('id')->all());
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.organization.id', $this->beta->id);

        $accepted = AuditLog::query()->where('action', 'invitation.accepted')->sole();
        $this->assertSame($this->alpha->id, $accepted->organization_id);
        $this->assertSame($this->invitee->id, $accepted->actor_user_id);
        $added = AuditLog::query()->where('action', 'user.member_added')->sole();
        $this->assertSame($this->invitee->id, $added->actor_user_id);
        $this->assertSame($invitation->id, $added->metadata['invitation_id']);
        $this->assertSame($this->admin->id, $added->metadata['invited_by']);
        $this->assertSame(['SUPERVISOR'], $added->after['roles']);
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->toJson());
    }

    public function test_accepting_requires_a_session(): void
    {
        $token = $this->invite($this->invitee->email);
        $this->beGuest();

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 401, 'UNAUTHENTICATED');
        $this->assertNotMember($this->invitee);
    }

    public function test_another_account_cannot_accept_someone_elses_invitation(): void
    {
        $token = $this->invite($this->invitee->email);
        $other = $this->createTenantUser($this->beta, ['SUPERVISOR']);
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->loginAs($other);

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'INVITATION_EMAIL_MISMATCH');
        $this->assertNotMember($other);
        $this->assertSame('pending', OrganizationInvitation::query()->sole()->status);
    }

    public function test_unverified_email_cannot_accept(): void
    {
        $this->invitee->forceFill(['email_verified_at' => null])->save();
        $token = $this->invite($this->invitee->email);
        $this->loginAs($this->invitee);

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'EMAIL_NOT_VERIFIED');
        $this->assertNotMember($this->invitee);
    }

    /** The SEC-01 attack: an account squatting on someone's address, even holding the token, gets nothing. */
    public function test_account_pre_registered_with_a_victims_address_gains_no_access(): void
    {
        $this->postJson('/api/auth/register', [
            'organization_name' => 'PT Dummy', 'organization_code' => 'DUMMY', 'name' => 'Penyerang',
            'email' => 'budi@perusahaan.test', 'password' => 'Penyerang-Kuat-2026', 'password_confirmation' => 'Penyerang-Kuat-2026',
        ])->assertCreated();
        $attacker = User::query()->where('email', 'budi@perusahaan.test')->sole();
        $this->postJson('/api/auth/logout')->assertNoContent();

        $token = $this->invite('budi@perusahaan.test');
        $this->assertNotMember($attacker);

        $this->loginAs($attacker);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'EMAIL_NOT_VERIFIED');
        $this->assertNotMember($attacker);
    }

    public function test_invalid_expired_revoked_and_used_tokens_are_rejected(): void
    {
        $this->loginAs($this->invitee);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => str_repeat('0', 64)]), 404, 'INVITATION_INVALID');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => '']), 422, 'VALIDATION_FAILED');

        $expired = $this->invite('expired@luar.test');
        $this->travel(73)->hours();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $expired]), 410, 'INVITATION_EXPIRED');

        $revoked = $this->invite($this->invitee->email);
        $this->postJson('/api/invitations/'.OrganizationInvitation::query()->where('email', $this->invitee->email)->value('id').'/revoke')->assertOk();
        $this->loginAs($this->invitee);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $revoked]), 410, 'INVITATION_REVOKED');

        $this->assertNotMember($this->invitee);
    }

    public function test_an_invitation_is_accepted_only_once(): void
    {
        $token = $this->invite($this->invitee->email);

        $this->loginAs($this->invitee);
        $this->postJson('/api/auth/invitation/accept', ['token' => $token])->assertOk();

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 409, 'INVITATION_USED');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $token]), 409, 'INVITATION_USED');

        $this->assertSame(1, OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->invitee->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'user.member_added')->count());
    }

    /**
     * Two simultaneous acceptances: the invitation row is locked (SELECT ... FOR UPDATE) and
     * re-read before anything is written, and the database refuses a second membership anyway.
     */
    public function test_concurrent_acceptances_cannot_create_two_memberships(): void
    {
        $token = $this->invite($this->invitee->email);

        $this->loginAs($this->invitee);
        DB::enableQueryLog();
        $this->postJson('/api/auth/invitation/accept', ['token' => $token])->assertOk();
        $locks = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $q) => str_contains($q, 'organization_invitations') && str_contains($q, 'for update'));
        DB::disableQueryLog();
        $this->assertNotEmpty($locks, 'Acceptance must lock the invitation row');

        try {
            DB::transaction(fn () => (new OrganizationMember)->forceFill([
                'organization_id' => $this->alpha->id, 'user_id' => $this->invitee->id, 'status' => 'active',
            ])->save());
            $this->fail('A second membership must be rejected');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }
        $this->assertSame(1, OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->invitee->id)->count());
    }

    public function test_access_is_validated_again_when_accepting(): void
    {
        $manager = $this->createTenantUser($this->alpha, ['ADMIN']);
        $token = $this->invite($this->invitee->email, 'ADMIN', inviter: $manager);

        // The inviter lost the right to give this access before the invitation was accepted.
        $manager->roles()->detach();
        $manager->roles()->attach($this->roleId($this->alpha, 'SUPERVISOR'), ['organization_id' => $this->alpha->id]);

        $this->loginAs($this->invitee);
        $response = $this->postJson('/api/auth/invitation/accept', ['token' => $token]);
        $this->assertErrorCode($response, 410, 'INVITATION_UNAVAILABLE');
        $response->assertJsonPath('error.details.reason', 'inviter_lacks_permission');
        $this->assertNotMember($this->invitee);

        // Same when the inviter left the organization.
        $manager2 = $this->createTenantUser($this->alpha, ['ADMIN']);
        $token2 = $this->invite('lain@luar.test', inviter: $manager2);
        OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $manager2->id)->update(['status' => 'suspended']);
        $this->beGuest();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $this->signUp($token2)), 410, 'INVITATION_UNAVAILABLE');
        $this->assertFalse(User::query()->where('email', 'lain@luar.test')->exists());
    }

    public function test_inspect_describes_only_valid_tokens_without_secrets(): void
    {
        $token = $this->invite($this->invitee->email, 'SUPERVISOR');
        $this->beGuest();

        $this->postJson('/api/auth/invitation/inspect', ['token' => $token])->assertOk()
            ->assertJsonPath('data.email', $this->invitee->email)
            ->assertJsonPath('data.organization.code', 'ALPHA')
            ->assertJsonPath('data.invited_by', $this->admin->name)
            ->assertJsonPath('data.roles.0.code', 'SUPERVISOR')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.account_exists');

        // Unknown tokens get one answer, whatever addresses exist.
        $unknown = $this->postJson('/api/auth/invitation/inspect', ['token' => hash('sha256', 'x')]);
        $this->assertErrorCode($unknown, 404, 'INVITATION_INVALID');
        $this->assertSame($unknown->json('error.message'), $this->postJson('/api/auth/invitation/inspect', ['token' => 'tebakan'])->json('error.message'));
    }

    public function test_new_address_signs_up_through_the_invitation_and_joins(): void
    {
        $token = $this->invite('baru@luar.test', 'SUPERVISOR');
        $this->beGuest();

        $this->postJson('/api/auth/invitation/register', $this->signUp($token))->assertCreated()
            ->assertJsonPath('data.organization.id', $this->alpha->id);

        $user = User::query()->where('email', 'baru@luar.test')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('Rina Baru', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Rahasia-Kuat-2026', $user->password));
        $this->assertSame(['SUPERVISOR'], $user->roles()->pluck('code')->all());
        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.organization.id', $this->alpha->id)
            ->assertJsonPath('data.user.email_verified_at', $user->email_verified_at->toIso8601String());
        foreach (['auth.registered', 'auth.email_verified', 'user.member_added', 'invitation.accepted'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('actor_user_id', $user->id)->exists(), $action);
        }

        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $this->signUp($token)), 409, 'INVITATION_USED');
    }

    public function test_sign_up_through_an_invitation_is_refused_for_an_address_with_an_account(): void
    {
        $token = $this->invite($this->invitee->email);
        $this->beGuest();

        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $this->signUp($token)), 409, 'ACCOUNT_EXISTS');
        $this->postJson('/api/auth/invitation/register', [...$this->signUp($token), 'password_confirmation' => 'Lain-2026-Sekali'])->assertStatus(422);
        $this->assertGuest('web');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->invitee->fresh()->password));
        $this->assertNotMember($this->invitee);
    }

    public function test_employee_number_in_use_is_refused_when_inviting(): void
    {
        OrganizationMember::query()->where('user_id', $this->admin->id)->update(['employee_number' => 'K-001']);
        $this->loginAs($this->admin);

        $this->assertErrorCode($this->postJson('/api/invitations', [...$this->payload('satu@luar.test', 'SUPERVISOR'), 'scopes' => [['scope_type' => 'organization']], 'employee_number' => 'K-001']),
            409, 'EMPLOYEE_NUMBER_TAKEN');

        $this->invite('dua@luar.test', profile: ['employee_number' => 'K-002']);
        $this->loginAs($this->admin);
        $this->assertErrorCode($this->postJson('/api/invitations', [...$this->payload('tiga@luar.test', 'SUPERVISOR'), 'scopes' => [['scope_type' => 'organization']], 'employee_number' => 'K-002']),
            409, 'EMPLOYEE_NUMBER_TAKEN');
    }

    /** The number was free when invited but taken since: a clear refusal, not a bare DUPLICATE, and no account is left behind. */
    public function test_sign_up_is_refused_when_the_invited_employee_number_was_taken_since(): void
    {
        $token = $this->invite('baru@luar.test', profile: ['employee_number' => 'K-003']);
        OrganizationMember::query()->where('user_id', $this->admin->id)->update(['employee_number' => 'K-003']);
        $this->beGuest();

        $response = $this->postJson('/api/auth/invitation/register', $this->signUp($token));
        $this->assertErrorCode($response, 410, 'INVITATION_UNAVAILABLE');
        $response->assertJsonPath('error.details.reason', 'employee_number_taken');
        $this->assertFalse(User::query()->where('email', 'baru@luar.test')->exists());
    }

    public function test_temporary_password_must_be_changed_before_accepting(): void
    {
        $this->invitee->forceFill(['must_change_password' => true])->save();
        $token = $this->invite($this->invitee->email);
        $this->loginAs($this->invitee);

        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 403, 'PASSWORD_CHANGE_REQUIRED');
        $this->assertNotMember($this->invitee);
    }

    /**
     * Sends an invitation as $inviter (default: the ALPHA administrator) and returns the emailed token.
     * The inviter stays logged in afterwards.
     *
     * @param  list<array{scope_type: string, ref_id?: string}>  $scopes
     * @param  array<string, mixed>  $profile
     */
    private function invite(string $email, string $role = 'SUPERVISOR', array $scopes = [['scope_type' => 'organization']], array $profile = [], ?User $inviter = null): string
    {
        $this->loginAs($inviter ?? $this->admin);
        $this->postJson('/api/invitations', [...$this->payload($email, $role), 'scopes' => $scopes, ...$profile])->assertCreated();

        return $this->sentInvitationToken($email, last: true);
    }

    /**
     * A fresh session per account, as a real login gives: Sanctum's AuthenticateSession pins the
     * session to the first account's password hash and logs any other account out (401).
     */
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

    /** @return array<string, mixed> */
    private function payload(string $email, string $role): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->alpha, $role)], 'scopes' => [['scope_type' => 'organization']], 'license_code_id' => $this->licenseCode()->id];
    }

    /** Every invitation needs an available license code of ALPHA; a fresh one per invitation. */
    private function licenseCode(): LicenseCode
    {
        $code = new LicenseCode;
        $code->forceFill(['organization_id' => $this->alpha->id, 'code' => 'LIC-'.implode('-', str_split(strtoupper(Str::random(12)), 4)), 'status' => 'available'])->save();

        return $code;
    }

    /** @return array<string, string> */
    private function signUp(string $token): array
    {
        return ['token' => $token, 'name' => 'Rina Baru', 'password' => 'Rahasia-Kuat-2026', 'password_confirmation' => 'Rahasia-Kuat-2026'];
    }

    private function assertNotMember(User $user): void
    {
        $this->assertFalse(OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $user->id)->exists());
        $this->assertFalse(DB::table('user_roles')->where('organization_id', $this->alpha->id)->where('user_id', $user->id)->exists());
    }

    private function location(): Location
    {
        $location = (new Location)->forceFill(['organization_id' => $this->alpha->id, 'code' => 'GDG', 'name' => 'Gudang',
            'location_type_id' => LocationType::query()->where('code', 'GUDANG')->value('id')]);
        $location->save();

        return $location;
    }
}
