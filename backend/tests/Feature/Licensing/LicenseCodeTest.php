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

    public function test_license_codes_cannot_be_revoked(): void
    {
        $this->loginAs($this->admin);
        $code = $this->createLicenseCode();

        $this->postJson("/api/license-codes/{$code->id}/revoke")->assertNotFound();
        $this->assertSame('available', $code->refresh()->status);
        $this->getJson('/api/license-codes?status=revoked')->assertStatus(422);
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

    public function test_codes_are_searched_by_code_reference_or_holder_and_counted_per_status(): void
    {
        $this->loginAs($this->admin);
        $holder = $this->createTenantUser($this->organization, ['SUPERVISOR'], attributes: ['name' => 'Rina Holder']);
        $assigned = $this->createLicenseCode('LIC-AAAA-0000-0001', 'REF-ALPHA');
        $this->createLicenseCode('LIC-BBBB-0000-0002', 'REF-BETA');
        $this->postJson("/api/license-codes/{$assigned->id}/assign", ['user_id' => $holder->id])->assertOk();

        $this->assertSame(['LIC-AAAA-0000-0001'], $this->getJson('/api/license-codes?search=rina')->assertOk()->json('data.*.code'));
        $this->assertSame(['LIC-BBBB-0000-0002'], $this->getJson('/api/license-codes?search=beta')->assertOk()->json('data.*.code'));
        $this->assertSame(['LIC-BBBB-0000-0002'], $this->getJson('/api/license-codes?search=bbbb')->assertOk()->json('data.*.code'));

        // Counts follow the search but not the status filter, so every status tab keeps its number.
        $this->getJson('/api/license-codes?search=REF-&status=assigned')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('counts', ['available' => 1, 'assigned' => 1]);
    }

    public function test_listed_codes_carry_the_validity_of_their_license(): void
    {
        $this->loginAs($this->admin);
        $this->postJson('/api/licenses/purchase', ['quantity' => 1])->assertCreated();

        $code = collect($this->getJson('/api/license-codes?search=PURCHASE-')->assertOk()->json('data'))->sole();

        $this->assertSame('base', $code['license']['kind']);
        $this->assertSame('active', $code['license']['status']);
        $this->assertNotNull($code['license']['expires_at']);
    }

    public function test_code_is_only_assigned_to_a_member_without_another_code(): void
    {
        $this->loginAs($this->admin);
        $first = $this->createLicenseCode('LIC-AAAA-0000-0001');
        $second = $this->createLicenseCode('LIC-AAAA-0000-0002');
        $member = $this->createTenantUser($this->organization, ['SUPERVISOR']);
        ['admin' => $outsider] = $this->createOrganization('OTHER');

        $this->assertErrorCode($this->postJson("/api/license-codes/{$first->id}/assign", ['user_id' => $outsider->id]), 422, 'USER_NOT_MEMBER');

        $this->postJson("/api/license-codes/{$first->id}/assign", ['user_id' => $member->id])->assertOk();
        $this->assertErrorCode($this->postJson("/api/license-codes/{$second->id}/assign", ['user_id' => $member->id]), 409, 'USER_ALREADY_LICENSED');
        $this->assertSame('available', $second->refresh()->status);
    }

    public function test_code_history_records_assignments_and_releases(): void
    {
        $this->loginAs($this->admin);
        $code = $this->createLicenseCode();
        $budi = $this->createTenantUser($this->organization, ['SUPERVISOR'], attributes: ['name' => 'Budi Member']);
        $sari = $this->createTenantUser($this->organization, ['SUPERVISOR'], attributes: ['name' => 'Sari Member']);

        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $budi->id])->assertOk();
        $this->postJson("/api/license-codes/{$code->id}/release")->assertOk();
        // A released code is reusable.
        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $sari->id])->assertOk();

        $history = $this->getJson("/api/license-codes/{$code->id}")->assertOk()->json('data.history');
        $this->assertSame(['license_code.assigned', 'license_code.released', 'license_code.assigned'], array_column($history, 'action'));
        $this->assertSame(['Sari Member', 'Budi Member', 'Budi Member'], array_column($history, 'user_name'));
        $this->assertSame($this->admin->name, $history[0]['actor']['name']);
    }

    public function test_a_code_on_an_open_invitation_is_reserved_for_that_invitee(): void
    {
        $this->loginAs($this->admin);
        $code = $this->createLicenseCode();
        $invite = fn (string $email) => $this->postJson('/api/invitations', [
            'email' => $email,
            'role_ids' => [$this->roleId($this->organization, 'SUPERVISOR')],
            'scopes' => [['scope_type' => 'organization']],
            'license_code_id' => $code->id,
        ]);

        $invite('first@test.com')->assertCreated();
        $this->assertErrorCode($invite('second@test.com'), 409, 'LICENSE_CODE_RESERVED');

        $listed = collect($this->getJson('/api/license-codes?search=LIC-TEST')->assertOk()->json('data'))->sole();
        $this->assertSame('available', $listed['status']);
        $this->assertSame('first@test.com', $listed['pending_invitation']['email']);
    }

    public function test_deactivating_a_member_releases_their_code_with_a_history_entry(): void
    {
        $this->loginAs($this->admin);
        $code = $this->createLicenseCode();
        $member = $this->createTenantUser($this->organization, ['SUPERVISOR']);
        $this->postJson("/api/license-codes/{$code->id}/assign", ['user_id' => $member->id])->assertOk();

        $this->postJson("/api/users/{$member->id}/deactivate")->assertOk();

        $this->assertSame('available', $code->refresh()->status);
        $released = AuditLog::query()->where('action', 'license_code.released')->where('entity_id', $code->id)->sole();
        $this->assertSame('user_deactivated', $released->metadata['reason']);
    }

    /** @return Collection<int, LicenseCode> */
    private function purchasedCodes(): Collection
    {
        return LicenseCode::query()->where('organization_id', $this->organization->id)->where('reference', 'like', 'PURCHASE-%')->get();
    }

    private function createLicenseCode(string $value = 'LIC-TEST-0000-0000', ?string $reference = null): LicenseCode
    {
        $code = new LicenseCode;
        $code->forceFill([
            'organization_id' => $this->organization->id,
            'code' => $value,
            'status' => 'available',
            'reference' => $reference,
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
