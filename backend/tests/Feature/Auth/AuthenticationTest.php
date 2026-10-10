<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_tenant_user_logs_in_and_receives_profile_with_permissions(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');

        $response = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.user_type', 'tenant')
            ->assertJsonPath('data.organization.code', 'ALPHA')
            ->assertJsonPath('data.data_scope.organization_wide', true);
        $this->assertContains('user.create', $response->json('data.permissions'));
        $this->assertNotContains('platform.organization.manage', $response->json('data.permissions'));
        $this->assertAuthenticatedAs($admin, 'web');

        $log = AuditLog::query()->where('action', 'auth.login')->sole();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_generically_and_audited_without_secrets(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');

        $response = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Salah-Sekali-999']);

        $this->assertErrorCode($response, 422, 'INVALID_CREDENTIALS');
        $this->assertGuest('web');
        $log = AuditLog::query()->where('action', 'auth.login_failed')->sole();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame('bad_password', $log->metadata['reason']);
        $this->assertStringNotContainsString('Salah-Sekali-999', json_encode($log->getAttributes()));
    }

    public function test_unknown_email_gets_the_same_error_as_wrong_password(): void
    {
        $response = $this->postJson('/api/auth/login', ['email' => 'tidak-ada@example.test', 'password' => 'Apapun-12345']);

        $this->assertErrorCode($response, 422, 'INVALID_CREDENTIALS');
        $log = AuditLog::query()->where('action', 'auth.login_failed')->sole();
        $this->assertNull($log->organization_id);
        $this->assertSame('unknown_email', $log->metadata['reason']);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        ['organization' => $org] = $this->createOrganization('ALPHA');
        $user = $this->createTenantUser($org, ['VIEWER'], attributes: []);
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertErrorCode(
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]),
            422, 'INVALID_CREDENTIALS',
        );
        $this->assertGuest('web');
    }

    public function test_user_of_suspended_organization_cannot_log_in(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA', Organization::STATUS_SUSPENDED);

        $this->assertErrorCode(
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD]),
            403, 'ORGANIZATION_INACTIVE',
        );
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Salah-Sekali-999'])->assertStatus(422);
        }

        $this->assertErrorCode(
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD]),
            429, 'TOO_MANY_REQUESTS',
        );
    }

    public function test_logout_ends_the_session(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertOk();

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.logout')->exists());
    }

    public function test_guest_receives_401_envelope(): void
    {
        $this->assertErrorCode($this->getJson('/api/users'), 401, 'UNAUTHENTICATED');
        $this->assertErrorCode($this->getJson('/api/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_user_must_change_temporary_password_before_using_tenant_api(): void
    {
        ['organization' => $org] = $this->createOrganization('ALPHA');
        $user = $this->createTenantUser($org, ['VIEWER']);
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user, 'web');

        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.user.must_change_password', true);

        $this->putJson('/api/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Baru-Rahasia-2026',
            'password_confirmation' => 'Baru-Rahasia-2026',
        ])->assertOk()->assertJsonPath('data.user.must_change_password', false);

        $this->getJson('/api/organization')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.password_changed')->where('actor_user_id', $user->id)->exists());
    }

    public function test_password_change_enforces_policy_and_current_password(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');

        $this->putJson('/api/auth/password', [
            'current_password' => 'Bukan-Password-Lama1',
            'password' => 'Baru-Rahasia-2026',
            'password_confirmation' => 'Baru-Rahasia-2026',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);

        $this->putJson('/api/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_platform_user_profile_has_platform_permissions_only(): void
    {
        $platform = $this->createPlatformAdmin();
        $this->actingAs($platform, 'web');

        $response = $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.user.user_type', 'platform')
            ->assertJsonPath('data.organization', null);
        $this->assertContains('platform.organization.manage', $response->json('data.permissions'));
        $this->assertNotContains('asset.view', $response->json('data.permissions'));
    }
}
