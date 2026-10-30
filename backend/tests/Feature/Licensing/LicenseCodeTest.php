<?php

namespace Tests\Feature\Licensing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
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

    public function test_purchase_creates_a_license_code_for_every_license(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/licenses/purchase', ['quantity' => 3, 'notes' => 'Initial batch'])->assertCreated()
            ->assertJsonPath('meta.purchased', 3);

        $codes = $this->purchasedCodes();
        $this->assertCount(3, $codes);
        $this->assertSame(['available'], $codes->pluck('status')->unique()->values()->all());
        $this->assertSame('Initial batch', $codes->first()->notes);
        $this->assertMatchesRegularExpression('/^LIC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codes->first()->code);
        // Every code points at the license it was bought with.
        $this->assertEqualsCanonicalizing(
            OrganizationLicense::query()->where('organization_id', $this->organization->id)->where('reference', 'like', 'PURCHASE-%')->pluck('reference')->all(),
            $codes->pluck('reference')->all(),
        );
    }

    public function test_license_codes_and_references_are_unique_across_purchases(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/licenses/purchase', ['quantity' => 2])->assertCreated();
        $this->postJson('/api/licenses/purchase', ['quantity' => 3])->assertCreated();

        $codes = $this->purchasedCodes();
        $this->assertCount(5, $codes->pluck('code')->unique());
        $this->assertCount(5, $codes->pluck('reference')->unique());
    }

    public function test_license_codes_are_only_created_by_purchases(): void
    {
        $this->loginAs($this->admin);

        $this->postJson('/api/license-codes', ['count' => 3])->assertStatus(405);
        $this->assertCount(0, $this->purchasedCodes());
    }

    public function test_only_the_super_admin_buys_licenses_and_manages_codes(): void
    {
        $this->loginAs($this->createTenantUser($this->organization, ['SUPERVISOR']));

        $this->assertErrorCode($this->postJson('/api/licenses/purchase', ['quantity' => 1]), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/license-codes'), 403, 'FORBIDDEN');
        $this->assertCount(0, $this->purchasedCodes());
    }

    public function test_admin_can_list_available_license_codes(): void
    {
        $this->loginAs($this->admin);
        $available = fn () => $this->getJson('/api/license-codes?status=available&per_page=100')->assertOk()->json('meta.total');
        $before = $available();

        $this->postJson('/api/licenses/purchase', ['quantity' => 5])->assertCreated();

        $this->assertSame($before + 5, $available());
    }

    public function test_admin_can_assign_license_code_to_user(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $user = $this->createTenantUser($this->organization, ['SUPERVISOR']);

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
        $user1 = $this->createTenantUser($this->organization, ['SUPERVISOR']);
        $user2 = $this->createTenantUser($this->organization, ['SUPERVISOR']);

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user1->id])->assertOk();

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $user2->id])->assertStatus(409)
            ->assertJsonPath('error.code', 'CODE_NOT_AVAILABLE');
    }

    public function test_admin_can_release_license_code(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $user = $this->createTenantUser($this->organization, ['SUPERVISOR']);

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
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
            'scopes' => [['scope_type' => 'organization']],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $code = $this->createLicenseCode();
        $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
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
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
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

    public function test_registration_requires_license_code_when_the_invitation_has_none(): void
    {
        $this->loginAs($this->admin);

        $code = $this->createLicenseCode();
        $id = $this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $this->licenseCodeId($this->organization),
        ])->assertCreated()->json('data.id');
        // As for an invitation to another organization, which carries no code of its own.
        OrganizationInvitation::query()->whereKey($id)->update(['license_code_id' => null]);

        $token = $this->sentInvitationToken('new@test.com', last: true);
        $signUp = ['token' => $token, 'name' => 'New User', 'password' => 'Password-123', 'password_confirmation' => 'Password-123'];

        $this->flushSession();
        $this->assertErrorCode($this->postJson('/api/auth/invitation/register', $signUp), 422, 'LICENSE_CODE_REQUIRED');
        $this->assertFalse(User::query()->where('email', 'new@test.com')->exists());

        $this->postJson('/api/auth/invitation/register', [...$signUp, 'license_code' => $code->code])->assertCreated();
        $this->assertSame(User::query()->where('email', 'new@test.com')->value('id'), $code->refresh()->assigned_to_user_id);
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

        $this->assertErrorCode($this->postJson('/api/invitations', [
            'email' => 'new@test.com',
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ]), 409, 'INVALID_LICENSE_CODE');
        $this->assertFalse(OrganizationInvitation::query()->where('email', 'new@test.com')->exists());
    }

    /** @return Collection<int, LicenseCode> */
    private function purchasedCodes(): Collection
    {
        return LicenseCode::query()->where('organization_id', $this->organization->id)->where('reference', 'like', 'PURCHASE-%')->get();
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
