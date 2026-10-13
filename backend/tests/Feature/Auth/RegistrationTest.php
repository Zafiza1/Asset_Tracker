<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private const VALID = [
        'organization_name' => 'PT Baru Jaya',
        'organization_code' => 'baru',
        'name' => 'Rina Pendaftar',
        'email' => 'rina@baru.test',
        'password' => 'Rahasia-Kuat-2026',
        'password_confirmation' => 'Rahasia-Kuat-2026',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_sign_up_creates_an_organization_with_the_registrant_as_admin_and_logs_in(): void
    {
        $response = $this->postJson('/api/auth/register', self::VALID);

        $response->assertCreated()
            ->assertJsonPath('data.organization.code', 'BARU')
            ->assertJsonPath('data.user.email', 'rina@baru.test')
            ->assertJsonPath('data.user.must_change_password', false)
            ->assertJsonPath('data.data_scope.organization_wide', true);
        $this->assertContains('asset.create', $response->json('data.permissions'));

        $org = Organization::query()->where('code', 'BARU')->sole();
        $user = User::query()->where('email', 'rina@baru.test')->sole();
        $this->assertSame('PT Baru Jaya', $org->name);
        $this->assertSame($org->id, $user->organization_id);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(6, Role::query()->where('organization_id', $org->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.registered')->where('actor_user_id', $user->id)->exists());
    }

    public function test_taken_code_and_email_are_rejected_without_creating_anything(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');

        $response = $this->postJson('/api/auth/register', [...self::VALID, 'organization_code' => 'alpha', 'email' => $admin->email]);

        $response->assertStatus(422)->assertJsonPath('error.details.fields.organization_code.0', 'Kode organisasi sudah dipakai.');
        $this->assertSame('Email sudah terdaftar. Silakan masuk.', $response->json('error.details.fields.email.0'));
        $this->assertSame(1, Organization::query()->count());
        $this->assertGuest('web');
    }

    public function test_weak_or_unconfirmed_password_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [...self::VALID, 'password' => 'lemah', 'password_confirmation' => 'lemah'])->assertStatus(422);
        $this->postJson('/api/auth/register', [...self::VALID, 'password_confirmation' => 'Beda-Sekali-2026'])->assertStatus(422);
        $this->assertSame(0, Organization::query()->count());
    }

    public function test_sign_up_can_be_disabled(): void
    {
        config(['auth.self_registration' => false]);

        $this->assertErrorCode($this->postJson('/api/auth/register', self::VALID), 403, 'REGISTRATION_DISABLED');
        $this->assertSame(0, Organization::query()->count());
    }
}
