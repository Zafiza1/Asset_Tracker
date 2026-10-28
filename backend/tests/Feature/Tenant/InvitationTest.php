<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The administrator's side of invitations: create, list, resend, revoke, isolation. */
class InvitationTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
    }

    public function test_admin_invites_an_address_and_only_the_token_hash_is_stored(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/invitations', $this->payload('budi@alpha.test'))->assertCreated()
            ->assertJsonPath('data.email', 'budi@alpha.test')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.roles.0.code', 'SUPERVISOR')
            ->assertJsonPath('data.scopes', [['scope_type' => 'organization', 'ref_id' => null]])
            ->assertJsonPath('data.invited_by.id', $this->admin->id)
            ->assertJsonPath('data.send_count', 1)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $token = $this->sentToken('budi@alpha.test');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $invitation = OrganizationInvitation::query()->findOrFail($response->json('data.id'));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame($this->org->id, $invitation->organization_id);
        // Valid for 72 hours by default.
        $this->assertEqualsWithDelta(now()->addHours(72)->getTimestamp(), $invitation->expires_at->getTimestamp(), 5);
        $this->assertStringContainsString('/undangan#token='.$token, OrganizationInvitationNotification::url($token));

        $log = AuditLog::query()->where('action', 'invitation.created')->sole();
        $this->assertSame($this->org->id, $log->organization_id);
        $this->assertSame(['SUPERVISOR'], $log->after['roles']);
        $this->assertStringNotContainsString($token, json_encode($log->getAttributes()));
        $this->assertStringNotContainsString($invitation->token_hash, json_encode($log->getAttributes()));
    }

    public function test_inviting_requires_user_create_and_role_manage(): void
    {
        $operator = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $creatorOnly = $this->createTenantUser($this->org, []);
        $creatorOnly->roles()->attach($this->customRole('CREATOR', ['user.view', 'user.create'])->id, ['organization_id' => $this->org->id]);

        foreach ([$operator, $creatorOnly] as $user) {
            $this->actingAs($user);
            $this->assertErrorCode($this->postJson('/api/invitations', $this->payload('x@alpha.test')), 403, 'FORBIDDEN');
        }
        $this->assertSame(0, OrganizationInvitation::query()->count());
        Notification::assertNothingSent();
    }

    public function test_invitation_creates_neither_an_account_nor_a_membership(): void
    {
        ['organization' => $beta, 'admin' => $existing] = $this->createOrganization('BETA');
        $this->actingAs($this->admin);

        $this->postJson('/api/invitations', $this->payload('baru@luar.test'))->assertCreated();
        $this->postJson('/api/invitations', $this->payload($existing->email))->assertCreated();

        $this->assertFalse(User::query()->where('email', 'baru@luar.test')->exists());
        $this->assertSame([$beta->id], $existing->memberships()->pluck('organization_id')->all());
        $this->assertSame(1, OrganizationMember::query()->where('organization_id', $this->org->id)->count());
        $this->getJson('/api/users')->assertOk()->assertJsonCount(1, 'data');
    }

    /** SEC-02 as part of SEC-01: the answer must not tell whether an address has an account. */
    public function test_response_does_not_reveal_whether_the_address_has_an_account(): void
    {
        ['admin' => $existing] = $this->createOrganization('BETA');
        $platform = $this->createPlatformAdmin();
        $this->actingAs($this->admin);

        $shapes = [];
        foreach (['tidak-ada@luar.test', $existing->email, $platform->email] as $email) {
            $response = $this->postJson('/api/invitations', $this->payload($email))->assertCreated();
            $data = $response->json('data');
            $shapes[] = [array_keys($data), $data['status'], $data['roles'], $response->json('meta')];
        }
        $this->assertCount(1, array_unique(array_map('serialize', $shapes)));
        Notification::assertSentOnDemandTimes(OrganizationInvitationNotification::class, 3);
    }

    public function test_roles_beyond_the_inviters_permissions_are_rejected(): void
    {
        $manager = $this->createTenantUser($this->org, []);
        $manager->roles()->attach($this->customRole('USER_ADMIN', ['user.view', 'user.create', 'role.view', 'role.manage'])->id, ['organization_id' => $this->org->id]);
        $this->actingAs($manager);

        $this->assertErrorCode($this->postJson('/api/invitations', $this->payload('x@alpha.test', 'ADMIN')), 403, 'PRIVILEGE_ESCALATION');
        $this->assertErrorCode($this->postJson('/api/invitations', [
            ...$this->payload('x@alpha.test'), 'role_ids' => [$this->roleId($this->createOrganization('BETA')['organization'], 'SUPERVISOR')],
        ]), 422, 'INVALID_ROLE');
        $this->assertSame(0, OrganizationInvitation::query()->count());
    }

    public function test_scope_limited_inviter_cannot_grant_access_outside_their_scope(): void
    {
        $warehouse = $this->location('GDG');
        $rack = $this->location('RAK', $warehouse);
        $office = $this->location('KTR');
        $manager = $this->createTenantUser($this->org, ['ADMIN'], [['scope_type' => 'location', 'ref_id' => $warehouse->id]]);
        $this->actingAs($manager);

        foreach ([[['scope_type' => 'organization']], [['scope_type' => 'location', 'ref_id' => $office->id]]] as $i => $scopes) {
            $this->assertErrorCode($this->postJson('/api/invitations', [...$this->payload("x{$i}@alpha.test"), 'scopes' => $scopes]), 403, 'SCOPE_ESCALATION');
        }
        $this->postJson('/api/invitations', [...$this->payload('rak@alpha.test'), 'scopes' => [['scope_type' => 'location', 'ref_id' => $rack->id]]])
            ->assertCreated()->assertJsonPath('data.scopes.0.ref_id', $rack->id);
    }

    public function test_one_pending_invitation_per_address_and_expired_ones_do_not_block(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/invitations', $this->payload('budi@alpha.test'))->assertCreated();

        $this->assertErrorCode($this->postJson('/api/invitations', $this->payload('BUDI@alpha.test')), 409, 'INVITATION_PENDING');

        $this->travel(73)->hours();
        $this->postJson('/api/invitations', $this->payload('budi@alpha.test'))->assertCreated();
        $this->assertEqualsCanonicalizing(['expired', 'pending'], OrganizationInvitation::query()->pluck('status')->all());

        // The database enforces it as well (partial unique index).
        $this->expectQueryError('23505', fn () => DB::table('organization_invitations')->insert([
            'id' => (string) str()->uuid(), 'organization_id' => $this->org->id, 'email' => 'Budi@Alpha.test', 'token_hash' => str_repeat('a', 64),
            'invited_by' => $this->admin->id, 'role_ids' => '["x"]', 'scopes' => '[]', 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_resend_replaces_the_token_and_restarts_the_validity(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/invitations', $this->payload('budi@alpha.test'))->json('data.id');
        $oldToken = $this->sentToken('budi@alpha.test');

        $this->travel(80)->hours();
        $this->postJson("/api/invitations/{$id}/resend")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.send_count', 2);

        $newToken = $this->sentToken('budi@alpha.test', last: true);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $oldToken]), 404, 'INVITATION_INVALID');
        $this->postJson('/api/auth/invitation/inspect', ['token' => $newToken])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.resent')->exists());
    }

    public function test_revoked_invitation_is_final(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/invitations', $this->payload('budi@alpha.test'))->json('data.id');
        $token = $this->sentToken('budi@alpha.test');

        $this->postJson("/api/invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertErrorCode($this->postJson("/api/invitations/{$id}/resend"), 409, 'INVITATION_CLOSED');
        $this->assertErrorCode($this->postJson("/api/invitations/{$id}/revoke"), 409, 'INVITATION_CLOSED');
        $this->assertErrorCode($this->postJson('/api/auth/invitation/inspect', ['token' => $token]), 410, 'INVITATION_REVOKED');
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.revoked')->where('entity_id', $id)->exists());
        // Not even the database lets a revoked invitation be reopened.
        $this->expectQueryError('P0001', fn () => DB::table('organization_invitations')->where('id', $id)->update(['status' => 'pending']));
    }

    public function test_list_shows_effective_status_and_filters(): void
    {
        $this->actingAs($this->admin);
        $expiring = $this->postJson('/api/invitations', $this->payload('lama@alpha.test'))->json('data.id');
        $this->travel(73)->hours();
        $this->postJson('/api/invitations', $this->payload('baru@alpha.test'))->assertCreated();
        $revoked = $this->postJson('/api/invitations', $this->payload('batal@alpha.test'))->json('data.id');
        $this->postJson("/api/invitations/{$revoked}/revoke")->assertOk();

        $this->getJson('/api/invitations')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/invitations?status=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'baru@alpha.test');
        // Past its expiry: shown as expired before any housekeeping ran.
        $this->getJson('/api/invitations?status=expired')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expiring)->assertJsonPath('data.0.status', 'expired');
        $this->getJson('/api/invitations?status=revoked')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/invitations?search=bar')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/invitations?status=galaxy')->assertStatus(422);

        $this->artisan('invitations:expire')->assertSuccessful();
        $this->assertSame('expired', OrganizationInvitation::query()->findOrFail($expiring)->status);
    }

    public function test_invitations_of_another_organization_are_invisible_and_untouchable(): void
    {
        ['organization' => $beta, 'admin' => $adminB] = $this->createOrganization('BETA');
        $this->actingAs($adminB);
        $id = $this->postJson('/api/invitations', [
            'email' => 'tamu@beta.test', 'role_ids' => [$this->roleId($beta, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin);
        $this->getJson('/api/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertErrorCode($this->postJson("/api/invitations/{$id}/resend"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/invitations/{$id}/revoke"), 404, 'NOT_FOUND');
        // The organization in the body is ignored: invitations always belong to the active one.
        $mine = $this->postJson('/api/invitations', [...$this->payload('tamu@beta.test'), 'organization_id' => $beta->id])->assertCreated()->json('data.id');
        $this->assertSame($this->org->id, OrganizationInvitation::query()->findOrFail($mine)->organization_id);
        $this->assertSame('pending', OrganizationInvitation::query()->findOrFail($id)->status);

        // Row-Level Security hides them even without the application's global scope.
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () use ($id, $beta) {
            $this->assertSame(0, DB::table('organization_invitations')->where('id', $id)->count());
            $this->expectQueryError('42501', fn () => DB::table('organization_invitations')->insert([
                'id' => (string) str()->uuid(), 'organization_id' => $beta->id, 'email' => 'x@beta.test', 'token_hash' => str_repeat('b', 64),
                'invited_by' => $this->admin->id, 'role_ids' => '["x"]', 'scopes' => '[]', 'expires_at' => now()->addDay(),
            ]));
        });
        // The inviter must be a member of the invitation's organization (composite foreign key).
        $this->expectQueryError('23503', fn () => DB::table('organization_invitations')->insert([
            'id' => (string) str()->uuid(), 'organization_id' => $beta->id, 'email' => 'y@beta.test', 'token_hash' => str_repeat('c', 64),
            'invited_by' => $this->admin->id, 'role_ids' => '["x"]', 'scopes' => '[]', 'expires_at' => now()->addDay(),
        ]));
    }

    /** @return array<string, mixed> */
    private function payload(string $email, string $role = 'SUPERVISOR'): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->org, $role)], 'scopes' => [['scope_type' => 'organization']]];
    }

    private function sentToken(string $email, bool $last = false): string
    {
        return $this->sentInvitationToken($email, $last);
    }

    private function location(string $code, ?Location $parent = null): Location
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => app(OrganizationStructureService::class)
            ->createLocation(['code' => $code, 'name' => "Lokasi {$code}", 'parent_id' => $parent?->id,
                'location_type_id' => LocationType::query()->where('code', 'GUDANG')->value('id')]));
    }

    /** @param list<string> $permissions */
    private function customRole(string $code, array $permissions): Role
    {
        $role = new Role(['code' => $code, 'name' => $code]);
        $role->forceFill(['organization_id' => $this->org->id])->save();
        $ids = Permission::query()->whereIn('code', $permissions)->pluck('id');
        $role->permissions()->attach($ids->mapWithKeys(fn ($id) => [$id => ['organization_id' => $this->org->id]])->all());

        return $role;
    }

    private function expectQueryError(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }
}
