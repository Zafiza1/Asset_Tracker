<?php

namespace Tests\Feature\Licensing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** License code management: creation, assignment, validation, and release. */
class LicenseCodeTest extends TestCase
{
    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:5173');
        ['organization' => $this->organization, 'admin' => $this->admin] = $this->createOrganization('TEST');
    }

    public function test_admin_can_create_license_codes(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/license-codes', ['count' => 3, 'notes' => 'Initial batch'])->assertCreated()
            ->assertJsonPath('meta.created', 3);

        $codes = LicenseCode::query()->where('organization_id', $this->organization->id)->get();
        $this->assertCount(3, $codes);
        $this->assertSame('available', $codes->first()->status);
        $this->assertSame('Initial batch', $codes->first()->notes);
        $this->assertMatchesRegularExpression('/^LIC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codes->first()->code);
    }

    public function test_admin_can_create_single_license_code(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/license-codes', ['notes' => 'Single code'])->assertCreated()
            ->assertJsonPath('meta.created', 1);

        $code = LicenseCode::query()->where('organization_id', $this->organization->id)->sole();
        $this->assertSame('available', $code->status);
        $this->assertSame('Single code', $code->notes);
    }

    public function test_license_codes_are_unique(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/license-codes', ['count' => 10])->assertCreated();

        $codes = LicenseCode::query()->where('organization_id', $this->organization->id)->pluck('code');
        $this->assertCount(10, $codes->unique());
    }

    public function test_admin_can_list_available_license_codes(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/license-codes', ['count' => 5])->assertCreated();

        $this->getJson('/api/license-codes?status=available')->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_admin_can_assign_license_code_to_user(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $user = $this->createTenantUser($this->organization, ['VIEWER']);

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user->id])->assertOk();

        $code->refresh();
        $this->assertSame('assigned', $code->status);
        $this->assertSame($user->id, $code->assigned_to_user_id);
        $this->assertNotNull($code->assigned_at);
    }

    public function test_cannot_assign_already_assigned_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $user1 = $this->createTenantUser($this->organization, ['VIEWER']);
        $user2 = $this->createTenantUser($this->organization, ['VIEWER']);

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user1->id])->assertOk();

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user2->id])->assertStatus(409)
            ->assertJsonPath('error.code', 'CODE_NOT_AVAILABLE');
    }

    public function test_admin_can_release_license_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $user = $this->createTenantUser($this->organization, ['VIEWER']);

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user->id])->assertOk();
        $this->postJson("/api/license-codes/{$code->id}/release")->assertOk();

        $code->refresh();
        $this->assertSame('available', $code->status);
        $this->assertNull($code->assigned_to_user_id);
        $this->assertNull($code->assigned_at);
    }

    public function test_cannot_release_unassigned_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();

        $this->postJson("/api/license-codes/{$code->id}/release")->assertStatus(409)
            ->assertJsonPath('error.code', 'CODE_NOT_ASSIGNED');
    }

    public function test_admin_can_revoke_license_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();

        $this->postJson("/api/license-codes/{$code->id}/revoke")->assertOk();

        $code->refresh();
        $this->assertSame('revoked', $code->status);
    }

    public function test_invitation_requires_license_code(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'VIEWER')],
            'scopes' => [['scope_type' => 'organization']],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $code = $this->createLicenseCode();
        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'VIEWER')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ])->assertCreated();

        $invitation = OrganizationInvitation::query()->where('email', 'new@test.com')->sole();
        $this->assertSame($code->id, $invitation->license_code_id);
    }

    public function test_invitation_acceptance_assigns_license_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'VIEWER')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ])->assertCreated();

        $token = $this->sentInvitationToken('new@test.com', last: true);

        $this->flushSession();
        $this->postJson('/api/auth/invitation/register', [
            'token' => $token,
            'name' => 'New User',
            'license_code' => $code->code,
            'password' => 'Password-123',
            'password_confirmation' => 'Password-123',
        ])->assertCreated();

        $user = User::query()->where('email', 'new@test.com')->sole();
        $code->refresh();
        $this->assertSame('assigned', $code->status);
        $this->assertSame($user->id, $code->assigned_to_user_id);
    }

    public function test_registration_requires_license_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'VIEWER')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ])->assertCreated();

        $token = $this->sentInvitationToken('new@test.com', last: true);

        $this->flushSession();
        $this->postJson('/api/auth/invitation/register', [
            'token' => $token,
            'name' => 'New User',
            'password' => 'Password-123',
            'password_confirmation' => 'Password-123',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_license_code_validation(): void
    {
        $service = app(LicenseCodeService::class);

        $this->assertFalse($service->isValidFormat('INVALID'));
        $this->assertFalse($service->isValidFormat('LIC-123'));
        $this->assertFalse($service->isValidFormat('lic-abcd-1234-efgh'));
        $this->assertTrue($service->isValidFormat('LIC-ABCD-1234-EFGH'));
    }

    public function test_code_from_other_organization_cannot_be_used(): void
    {
        $this->loginAs($this->admin);

        ['organization' => $otherOrg] = $this->createOrganization('OTHER');
        $code = new LicenseCode;
        $code->forceFill([
            'organization_id' => $otherOrg->id,
            'code' => 'LIC-TEST-TEST-TEST',
            'status' => 'available',
        ]);
        $code->save();

        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'VIEWER')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ])->assertStatus(422);
    }

    private function createLicenseCode(): LicenseCode
    {
        $code = new LicenseCode;
        $code->forceFill([
            'organization_id' => $this->organization->id,
            'code' => 'LIC-TEST-0000-0000',
            'status' => 'available',
        ]);
        $code->save();

        return $code;
    }

    private function loginAs(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }
}
