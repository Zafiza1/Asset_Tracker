<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

/** An administrator of several organizations grants one account access to any of them. */
class MultiOrganizationUserTest extends TestCase
{
    private Organization $alpha;

    private Organization $beta;

    private Organization $gamma;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->alpha, 'admin' => $this->owner] = $this->createOrganization('ALPHA');
        ['organization' => $this->beta] = $this->createOrganization('BETA');
        ['organization' => $this->gamma] = $this->createOrganization('GAMMA');
        $this->addMembership($this->owner, $this->beta, 'ORG_ADMIN');
        // Only a viewer in GAMMA: may not add members there.
        $this->addMembership($this->owner, $this->gamma, 'VIEWER');
        $this->owner->forceFill(['last_organization_id' => $this->alpha->id])->save();
        $this->actingAs($this->owner);
    }

    public function test_options_list_only_manageable_organizations_with_their_roles(): void
    {
        $response = $this->getJson('/api/users/organization-options')->assertOk();

        $this->assertSame([$this->alpha->id, $this->beta->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.1.is_current', false);
        $this->assertContains($this->roleId($this->beta, 'OPERATOR'), array_column($response->json('data.1.roles'), 'id'));
        $this->assertNotContains($this->roleId($this->alpha, 'OPERATOR'), array_column($response->json('data.1.roles'), 'id'));
    }

    public function test_one_account_is_created_with_access_to_several_organizations(): void
    {
        $this->postJson('/api/users', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'OPERATOR')]],
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'AUDITOR')]],
        ]))->assertCreated()
            ->assertJsonPath('data.roles.0.code', 'OPERATOR')
            ->assertJsonPath('data.job_title', 'Staf Gudang')
            ->assertJsonPath('meta.organization_ids', [$this->alpha->id, $this->beta->id]);

        $user = User::query()->where('email', 'sari@grup.test')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $user->memberships()->pluck('organization_id')->all());
        $this->assertSame(['AUDITOR'], $user->roles()->wherePivot('organization_id', $this->beta->id)->pluck('code')->all());
        $this->assertSame('Staf Gudang', OrganizationMember::query()->where('organization_id', $this->beta->id)->where('user_id', $user->id)->value('job_title'));
        $this->assertTrue(AuditLog::query()->where('action', 'user.created')->where('organization_id', $this->alpha->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'user.member_added')->where('organization_id', $this->beta->id)->exists());

        // One login, both organizations available in the organization switcher.
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id],
            $this->app->make(OrganizationAccess::class)->accessible($user)->pluck('id')->all());
    }

    public function test_access_can_be_granted_to_other_organizations_only(): void
    {
        $this->postJson('/api/users', $this->payload([
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'VIEWER')]],
        ]))->assertCreated()->assertJsonPath('meta.organization_ids', [$this->beta->id]);

        $user = User::query()->where('email', 'sari@grup.test')->sole();
        $this->assertSame([$this->beta->id], $user->memberships()->pluck('organization_id')->all());
    }

    public function test_all_grants_are_rejected_when_one_organization_is_not_manageable(): void
    {
        $this->assertErrorCode($this->postJson('/api/users', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'OPERATOR')]],
            ['organization_id' => $this->gamma->id, 'role_ids' => [$this->roleId($this->gamma, 'VIEWER')]],
        ])), 403, 'ORGANIZATION_ACCESS_DENIED');

        $this->assertFalse(User::query()->where('email', 'sari@grup.test')->exists());
    }

    public function test_a_failing_organization_rolls_back_every_grant(): void
    {
        // A role of ALPHA cannot be assigned in BETA.
        $response = $this->postJson('/api/users', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'OPERATOR')]],
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->alpha, 'VIEWER')]],
        ]));

        $this->assertErrorCode($response, 422, 'INVALID_ROLE');
        $this->assertStringStartsWith('Organisasi BETA:', $response->json('error.message'));
        $this->assertFalse(User::query()->where('email', 'sari@grup.test')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'user.created')->exists());
    }

    public function test_membership_validation_errors_use_standard_envelope(): void
    {
        $this->postJson('/api/users', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => []],
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'VIEWER')]],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['memberships.0.role_ids', 'memberships.1.organization_id']]]]);
    }

    public function test_member_detail_lists_and_extends_organization_access(): void
    {
        $member = $this->createTenantUser($this->alpha, ['OPERATOR']);

        $this->getJson("/api/users/{$member->id}/organizations")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->alpha->id)
            ->assertJsonPath('data.0.roles.0.code', 'OPERATOR');

        $this->postJson("/api/users/{$member->id}/organizations", [
            'organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'APPROVER')],
        ])->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $this->beta->id)
            ->assertJsonPath('data.1.status', 'active')
            ->assertJsonPath('data.1.roles.0.code', 'APPROVER');

        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/organizations", [
            'organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'APPROVER')],
        ]), 409, 'ALREADY_MEMBER');
        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/organizations", [
            'organization_id' => $this->gamma->id, 'role_ids' => [$this->roleId($this->gamma, 'VIEWER')],
        ]), 403, 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_member_access_list_hides_organizations_the_actor_cannot_view(): void
    {
        $member = $this->createTenantUser($this->alpha, ['OPERATOR']);
        ['organization' => $delta] = $this->createOrganization('DELTA');
        $this->addMembership($member, $delta, 'VIEWER');

        $this->getJson("/api/users/{$member->id}/organizations")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->alpha->id);
    }

    /**
     * @param  list<array{organization_id: string, role_ids: list<string>}>  $memberships
     * @return array<string, mixed>
     */
    private function payload(array $memberships): array
    {
        return [
            'name' => 'Sari Wulandari',
            'email' => 'sari@grup.test',
            'password' => 'Sementara-Rahasia-1',
            'job_title' => 'Staf Gudang',
            'memberships' => $memberships,
        ];
    }
}
