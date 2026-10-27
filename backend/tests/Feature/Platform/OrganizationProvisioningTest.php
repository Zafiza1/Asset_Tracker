<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The platform console creates organizations; their first administrator is invited, never
 * assigned (see FirstAdminInvitationTest for the invitee's side).
 */
class OrganizationProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'code' => 'gamma',
            'name' => 'PT Gamma Sejahtera',
            'admin' => ['email' => 'admin@gamma.test'],
            'license' => ['expires_at' => now()->addYear()->toIso8601String(), 'seats' => 4, 'reference' => 'INV-001'],
        ], $overrides);
    }

    public function test_platform_admin_creates_an_organization_and_invites_its_first_administrator(): void
    {
        $platform = $this->createPlatformAdmin();
        $this->actingAs($platform, 'web');

        $response = $this->postJson('/api/platform/organizations', $this->payload(['admin' => ['email' => ' Admin@Gamma.test ']]));

        $response->assertCreated()
            ->assertJsonPath('data.code', 'GAMMA')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('meta.admin_invitation.email', 'admin@gamma.test')
            ->assertJsonPath('meta.admin_invitation.status', 'pending')
            ->assertJsonPath('meta.email_delivery', 'log')
            ->assertJsonMissingPath('meta.admin_user_id')
            ->assertJsonMissingPath('meta.admin_invitation.token');
        $org = Organization::query()->where('code', 'GAMMA')->sole();
        $this->assertSame(6, Role::query()->where('organization_id', $org->id)->count());
        $this->assertTrue(OrganizationSetting::query()->where('organization_id', $org->id)->exists());

        // Nobody has access yet; no account was created.
        $this->assertFalse(OrganizationMember::query()->where('organization_id', $org->id)->exists());
        $this->assertFalse(DB::table('user_roles')->where('organization_id', $org->id)->exists());
        $this->assertFalse(User::query()->where('email', 'admin@gamma.test')->exists());

        $invitation = OrganizationInvitation::query()->where('organization_id', $org->id)->sole();
        $this->assertSame($platform->id, $invitation->invited_by_platform_user_id);
        $this->assertNull($invitation->invited_by);
        $this->assertSame([$this->roleId($org, 'ORG_ADMIN')], $invitation->role_ids);
        $this->assertSame('organization', $invitation->scopes[0]['scope_type']);
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class,
            fn (OrganizationInvitationNotification $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'admin@gamma.test'
                && $n->organizationName === 'PT Gamma Sejahtera' && $n->inviterName === 'Administrator platform Asset Tracker');

        $created = AuditLog::query()->where('action', 'organization.created')->sole();
        $this->assertSame($org->id, $created->organization_id);
        $this->assertSame($platform->id, $created->actor_user_id);
        $invited = AuditLog::query()->where('action', 'invitation.created')->sole();
        $this->assertSame($org->id, $invited->organization_id);
        $this->assertSame($platform->id, $invited->actor_user_id);
        $this->assertSame('platform_console', $invited->metadata['source']);
        $this->assertSame(['ORG_ADMIN'], $invited->after['roles']);
        $token = $this->sentInvitationToken('admin@gamma.test');
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->toJson());
    }

    public function test_email_delivery_is_reported_as_mail_only_with_a_real_mailer(): void
    {
        config(['mail.default' => 'smtp']);
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->postJson('/api/platform/organizations', $this->payload())->assertCreated()->assertJsonPath('meta.email_delivery', 'mail');
    }

    public function test_an_existing_account_is_invited_not_made_a_member(): void
    {
        ['organization' => $alpha, 'admin' => $existing] = $this->createOrganization('ALPHA');
        $existing->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->postJson('/api/platform/organizations', $this->payload(['admin' => ['email' => $existing->email]]))->assertCreated();

        $gamma = Organization::query()->where('code', 'GAMMA')->sole();
        $this->assertSame([$alpha->id], $existing->memberships()->pluck('organization_id')->all());
        $this->assertFalse(DB::table('user_roles')->where('organization_id', $gamma->id)->where('user_id', $existing->id)->exists());
        $this->assertFalse(DB::table('user_data_scopes')->where('organization_id', $gamma->id)->where('user_id', $existing->id)->exists());
        $this->assertTrue(Hash::check(self::PASSWORD, $existing->fresh()->password));
        $this->assertSame('pending', OrganizationInvitation::query()->where('organization_id', $gamma->id)->sole()->status);
    }

    /** No password or name is accepted, so nothing in the request or response depends on the address having an account. */
    public function test_responses_do_not_reveal_whether_the_address_has_an_account(): void
    {
        ['admin' => $existing] = $this->createOrganization('ALPHA');
        $platformAccount = $this->createPlatformAdmin();
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $known = $this->postJson('/api/platform/organizations', $this->payload(['code' => 'known', 'admin' => ['email' => $existing->email]]));
        $unknown = $this->postJson('/api/platform/organizations', $this->payload(['code' => 'unknown', 'admin' => ['email' => 'belum-ada@luar.test']]));
        $platform = $this->postJson('/api/platform/organizations', $this->payload(['code' => 'plat', 'admin' => ['email' => $platformAccount->email]]));

        $known->assertCreated();
        $this->assertSame($this->shape($known), $this->shape($unknown));
        $this->assertSame($this->shape($known), $this->shape($platform));

        // Credentials for the administrator are refused outright, whatever the address.
        foreach ([$existing->email, 'lain@luar.test'] as $i => $email) {
            $this->postJson('/api/platform/organizations', $this->payload(['code' => "pw{$i}", 'admin' => ['email' => $email, 'password' => 'Awal-Rahasia-2026', 'name' => 'X']]))
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['admin']]]]);
        }
        $this->assertFalse(Organization::query()->whereIn('code', ['PW0', 'PW1'])->exists());
    }

    public function test_validation_errors_do_not_depend_on_the_address(): void
    {
        ['admin' => $existing] = $this->createOrganization('ALPHA');
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $a = $this->postJson('/api/platform/organizations', $this->payload(['code' => 'alpha', 'admin' => ['email' => $existing->email]]));
        $b = $this->postJson('/api/platform/organizations', $this->payload(['code' => 'alpha', 'admin' => ['email' => 'belum-ada@luar.test']]));

        $a->assertStatus(422);
        $b->assertStatus(422);
        $this->assertSame(Arr::except($a->json('error'), 'request_id'), Arr::except($b->json('error'), 'request_id'));
    }

    public function test_creation_is_atomic(): void
    {
        $this->actingAs($this->createPlatformAdmin(), 'web');
        $roles = Role::query()->count();
        $settings = OrganizationSetting::query()->count();
        // The invitation (last step) fails: the organization, its settings and roles are rolled back.
        OrganizationInvitation::creating(fn () => throw new RuntimeException('simulated failure'));

        $this->postJson('/api/platform/organizations', $this->payload())->assertStatus(500);

        $this->assertFalse(Organization::query()->where('code', 'GAMMA')->exists());
        $this->assertSame($roles, Role::query()->count());
        $this->assertSame($settings, OrganizationSetting::query()->count());
        $this->assertFalse(AuditLog::query()->whereIn('action', ['organization.created', 'invitation.created'])->exists());
        Notification::assertNothingSent();
    }

    public function test_platform_permission_is_required(): void
    {
        ['organization' => $alpha, 'admin' => $tenantAdmin] = $this->createOrganization('ALPHA');
        $org = $this->createAwaitingOrganization();

        $this->flushSession();
        $this->actingAs($this->createPlatformAdmin(withRole: false), 'web');
        $this->assertErrorCode($this->getJson('/api/platform/organizations'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson('/api/platform/organizations', $this->payload(['code' => 'delta'])), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson("/api/platform/organizations/{$org->id}/admin-invitation"), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation", ['email' => 'x@luar.test']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/resend"), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/revoke"), 403, 'FORBIDDEN');

        // View permission alone may see the onboarding state but not change it.
        $this->flushSession();
        $this->actingAs($this->createPlatformUserWith(['platform.organization.view']), 'web');
        $this->getJson("/api/platform/organizations/{$org->id}/admin-invitation")->assertOk();
        $this->assertErrorCode($this->postJson('/api/platform/organizations', $this->payload(['code' => 'delta'])), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation", ['email' => 'x@luar.test']), 403, 'FORBIDDEN');

        // A tenant administrator cannot use the platform console at all.
        $this->flushSession();
        $this->actingAs($tenantAdmin, 'web');
        $this->postJson('/api/platform/organizations', $this->payload(['code' => 'delta']))->assertForbidden();
        $this->postJson("/api/platform/organizations/{$org->id}/admin-invitation", ['email' => $tenantAdmin->email])->assertForbidden();

        $this->assertFalse(Organization::query()->where('code', 'DELTA')->exists());
        $this->assertSame(1, OrganizationInvitation::query()->where('organization_id', $org->id)->count());
        $this->assertFalse(OrganizationInvitation::query()->where('organization_id', $alpha->id)->exists());
    }

    /** The platform can only bootstrap an empty organization; it never places administrators into a running one. */
    public function test_platform_cannot_invite_administrators_into_an_organization_with_members(): void
    {
        ['organization' => $alpha] = $this->createOrganization('ALPHA');
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$alpha->id}/admin-invitation", ['email' => 'penyusup@luar.test']), 409, 'ORGANIZATION_HAS_MEMBERS');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$alpha->id}/admin-invitation/resend"), 409, 'ORGANIZATION_HAS_MEMBERS');
        $this->postJson('/api/platform/organizations/'.fake()->uuid().'/admin-invitation', ['email' => 'x@luar.test'])->assertNotFound();

        $this->assertFalse(OrganizationInvitation::query()->where('organization_id', $alpha->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_onboarding_state_resend_reinvite_and_revoke(): void
    {
        $platform = $this->createPlatformAdmin();
        $org = $this->createAwaitingOrganization($platform);
        $first = $this->sentInvitationToken('admin@gamma.test');

        $this->getJson("/api/platform/organizations/{$org->id}/admin-invitation")->assertOk()
            ->assertJsonPath('data.has_members', false)
            ->assertJsonPath('data.invitation.email', 'admin@gamma.test')
            ->assertJsonPath('data.invitation.status', 'pending')
            ->assertJsonPath('meta.email_delivery', 'log');

        // Resend: a new token, the previous link stops working.
        $this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/resend")->assertOk()->assertJsonPath('data.send_count', 2);
        $second = $this->sentInvitationToken('admin@gamma.test', last: true);
        $this->assertNotSame($first, $second);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $first]), 404, 'INVITATION_INVALID');

        // Another address replaces the open invitation.
        $this->postJson("/api/platform/organizations/{$org->id}/admin-invitation", ['email' => 'pengganti@gamma.test'])->assertCreated()
            ->assertJsonPath('data.email', 'pengganti@gamma.test');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $second]), 410, 'INVITATION_REVOKED');
        $this->assertSame(1, OrganizationInvitation::query()->where('organization_id', $org->id)->where('status', 'pending')->count());

        $this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $this->sentInvitationToken('pengganti@gamma.test')]), 410, 'INVITATION_REVOKED');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/resend"), 409, 'NO_OPEN_INVITATION');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/revoke"), 409, 'NO_OPEN_INVITATION');

        foreach (['invitation.resent', 'invitation.revoked'] as $action) {
            $log = AuditLog::query()->where('action', $action)->latest('created_at')->first();
            $this->assertSame($org->id, $log->organization_id, $action);
            $this->assertSame($platform->id, $log->actor_user_id, $action);
            $this->assertSame('platform_console', $log->metadata['source'], $action);
        }
    }

    public function test_a_suspended_organization_cannot_receive_an_administrator_invitation(): void
    {
        $org = $this->createAwaitingOrganization();
        $org->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();

        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation", ['email' => 'lain@gamma.test']), 409, 'ORGANIZATION_INACTIVE');
        $this->assertErrorCode($this->postJson("/api/platform/organizations/{$org->id}/admin-invitation/resend"), 409, 'ORGANIZATION_INACTIVE');
    }

    public function test_database_requires_exactly_one_inviter_and_a_platform_account_as_platform_inviter(): void
    {
        $org = $this->createAwaitingOrganization();
        $tenantUser = $this->createOrganization('ALPHA')['admin'];
        $row = fn (array $inviter) => DB::table('organization_invitations')->insert([
            'id' => fake()->uuid(), 'organization_id' => $org->id, 'email' => fake()->unique()->safeEmail(), 'token_hash' => hash('sha256', fake()->uuid()),
            'role_ids' => json_encode([$this->roleId($org, 'ORG_ADMIN')]), 'scopes' => '[]', 'status' => 'pending', 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(), ...$inviter,
        ]);

        foreach ([[], ['invited_by_platform_user_id' => $tenantUser->id]] as $inviter) {
            try {
                DB::transaction(fn () => $row($inviter));
                $this->fail('Invalid inviter must be rejected: '.json_encode($inviter));
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23514', 'P0001']);
            }
        }
    }

    public function test_suspending_an_organization_requires_reason_blocks_its_users_and_is_audited(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $platform = $this->createPlatformAdmin();
        $this->actingAs($platform, 'web');

        $this->postJson("/api/platform/organizations/{$org->id}/suspend")->assertStatus(422);
        $this->postJson("/api/platform/organizations/{$org->id}/suspend", ['reason' => 'Tunggakan kontrak'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $log = AuditLog::query()->where('action', 'organization.status_changed')->sole();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame('Tunggakan kontrak', $log->metadata['reason']);

        $this->actingAs($admin, 'web');
        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'ORGANIZATION_INACTIVE');
    }

    public function test_platform_lists_organizations_and_platform_audit(): void
    {
        $this->createOrganization('ALPHA');
        $this->createOrganization('BETA');
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->getJson('/api/platform/organizations?search=alp')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALPHA');

        $orgId = Organization::query()->where('code', 'BETA')->value('id');
        $actions = array_column($this->getJson("/api/platform/audit-logs?organization_id={$orgId}")->assertOk()->json('data'), 'action');
        $this->assertContains('organization.created', $actions);
    }

    /** Creates GAMMA through the console; the platform administrator stays logged in. */
    private function createAwaitingOrganization(?User $platform = null): Organization
    {
        $this->actingAs($platform ?? $this->createPlatformAdmin(), 'web');
        $this->postJson('/api/platform/organizations', $this->payload())->assertCreated();

        return Organization::query()->where('code', 'GAMMA')->sole();
    }

    /** @param list<string> $permissions */
    private function createPlatformUserWith(array $permissions): User
    {
        $user = User::factory()->platform()->create();
        $roleId = fake()->uuid();
        DB::table('platform_roles')->insert(['id' => $roleId, 'code' => 'TEST_'.strtoupper(fake()->lexify('????')), 'name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('platform_role_permissions')->insert(['platform_role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('platform_user_roles')->insert(['user_id' => $user->id, 'platform_role_id' => $roleId]);

        return $user;
    }

    /** Everything in a create response except what naturally differs per organization/invitation. @return array<string, mixed> */
    private function shape(TestResponse $response): array
    {
        $varying = ['data.id', 'data.code', 'data.created_at', 'data.updated_at', 'meta.admin_invitation.id', 'meta.admin_invitation.organization_id',
            'meta.admin_invitation.email', 'meta.admin_invitation.expires_at', 'meta.admin_invitation.last_sent_at', 'meta.admin_invitation.created_at'];

        return ['status' => $response->status(), ...Arr::except(Arr::dot($response->json()), $varying)];
    }
}
