<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One account, several organizations: the same login works for every organization the
 * account belongs to, the active one is chosen per session, and a logged-in user can
 * create further organizations.
 */
class MultiOrganizationTest extends TestCase
{
    private Organization $alpha;

    private Organization $beta;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
        ['organization' => $this->alpha, 'admin' => $this->owner] = $this->createOrganization('ALPHA');
        ['organization' => $this->beta] = $this->createOrganization('BETA');
        $this->addMembership($this->owner, $this->beta, 'SUPERVISOR');
    }

    public function test_same_login_lists_every_organization_and_requires_a_choice(): void
    {
        $response = $this->login();

        $response->assertOk()->assertJsonPath('data.organization', null)->assertJsonCount(2, 'data.organizations');
        $this->assertEqualsCanonicalizing(['ALPHA', 'BETA'], array_column($response->json('data.organizations'), 'code'));
        $this->assertErrorCode($this->getJson('/api/organization'), 409, 'ORGANIZATION_SELECTION_REQUIRED');
    }

    public function test_switching_changes_organization_permissions_and_data(): void
    {
        $this->login();

        $alpha = $this->putJson('/api/auth/organization', ['organization_id' => $this->alpha->id]);
        $alpha->assertOk()->assertJsonPath('data.organization.code', 'ALPHA');
        $this->assertContains('user.create', $alpha->json('data.permissions'));
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.id', $this->alpha->id);
        $this->getJson('/api/users')->assertOk();

        $beta = $this->putJson('/api/auth/organization', ['organization_id' => $this->beta->id]);
        $beta->assertOk()->assertJsonPath('data.organization.code', 'BETA');
        $this->assertNotContains('user.create', $beta->json('data.permissions'));
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.id', $this->beta->id);
        $this->assertErrorCode($this->getJson('/api/users'), 403, 'FORBIDDEN');

        $this->assertSame($this->beta->id, $this->owner->fresh()->last_organization_id);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.organization_switched')->where('organization_id', $this->beta->id)->exists());
    }

    public function test_last_used_organization_is_opened_on_next_login(): void
    {
        $this->owner->forceFill(['last_organization_id' => $this->beta->id])->save();

        $this->login()->assertOk()->assertJsonPath('data.organization.code', 'BETA');
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.id', $this->beta->id);
    }

    public function test_cannot_switch_to_an_organization_without_active_membership(): void
    {
        ['organization' => $gamma] = $this->createOrganization('GAMMA');
        $this->login();

        $this->assertErrorCode($this->putJson('/api/auth/organization', ['organization_id' => $gamma->id]), 403, 'ORGANIZATION_ACCESS_DENIED');

        $this->membershipIn($this->beta)->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertErrorCode($this->putJson('/api/auth/organization', ['organization_id' => $this->beta->id]), 403, 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_revoking_membership_takes_effect_immediately_without_affecting_other_organizations(): void
    {
        $this->login();
        $this->putJson('/api/auth/organization', ['organization_id' => $this->beta->id])->assertOk();

        $this->membershipIn($this->beta)->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'ORGANIZATION_INACTIVE');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.organization.code', 'ALPHA')->assertJsonCount(1, 'data.organizations');
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.id', $this->alpha->id);
    }

    public function test_client_assertion_of_a_stale_organization_is_rejected(): void
    {
        $this->login();
        $this->putJson('/api/auth/organization', ['organization_id' => $this->beta->id])->assertOk();

        // e.g. another browser tab switched to BETA while this one still shows ALPHA.
        $this->assertErrorCode($this->withHeader('X-Organization-Id', $this->alpha->id)->postJson('/api/departments', ['code' => 'X', 'name' => 'X']),
            409, 'ORGANIZATION_MISMATCH');
    }

    public function test_logged_in_user_creates_another_organization_with_the_same_account(): void
    {
        $this->login();

        $response = $this->postJson('/api/auth/organizations', ['organization_name' => 'PT Gamma Baru', 'organization_code' => 'gamma']);

        $response->assertCreated()
            ->assertJsonPath('data.organization.code', 'GAMMA')
            ->assertJsonPath('data.user.id', $this->owner->id)
            ->assertJsonCount(3, 'data.organizations');
        $this->assertContains('user.create', $response->json('data.permissions'));
        $gamma = Organization::query()->where('code', 'GAMMA')->sole();
        $this->assertSame(['ADMIN'], $this->owner->roles()->where('roles.organization_id', $gamma->id)->pluck('code')->all());
        $this->assertSame(1, User::query()->where('email', $this->owner->email)->count());
        $this->getJson('/api/organization')->assertOk()->assertJsonPath('data.id', $gamma->id);

        $this->postJson('/api/auth/organizations', ['organization_name' => 'Duplikat', 'organization_code' => 'GAMMA'])
            ->assertStatus(422)->assertJsonPath('error.details.fields.organization_code.0', 'Kode organisasi sudah dipakai.');
    }

    public function test_creating_organizations_follows_the_self_service_switch(): void
    {
        config(['auth.self_registration' => false]);
        $this->login();

        $this->assertErrorCode(
            $this->postJson('/api/auth/organizations', ['organization_name' => 'PT Gamma', 'organization_code' => 'GAMMA']),
            403, 'REGISTRATION_DISABLED',
        );
    }

    public function test_platform_user_cannot_switch_or_create_organizations(): void
    {
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->assertErrorCode($this->putJson('/api/auth/organization', ['organization_id' => $this->alpha->id]), 403, 'TENANT_USER_REQUIRED');
        $this->assertErrorCode($this->postJson('/api/auth/organizations', ['organization_name' => 'X', 'organization_code' => 'XX']), 403, 'TENANT_USER_REQUIRED');
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD]);
    }

    private function membershipIn(Organization $organization): OrganizationMember
    {
        return OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $this->owner->id)->sole();
    }
}
