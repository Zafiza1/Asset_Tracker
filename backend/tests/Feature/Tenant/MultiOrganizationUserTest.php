<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** An administrator of several organizations invites one address to any of them. */
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
        $this->addMembership($this->owner, $this->beta, 'ADMIN');
        // Only a viewer in GAMMA: may not add members there.
        $this->addMembership($this->owner, $this->gamma, 'SUPERVISOR');
        $this->owner->forceFill(['last_organization_id' => $this->alpha->id])->save();
        $this->actingAs($this->owner);
    }

    public function test_options_list_only_manageable_organizations_with_their_roles(): void
    {
        $response = $this->getJson('/api/users/organization-options')->assertOk();

        $this->assertSame([$this->alpha->id, $this->beta->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.1.is_current', false);
        $this->assertContains($this->roleId($this->beta, 'SUPERVISOR'), array_column($response->json('data.1.roles'), 'id'));
        $this->assertNotContains($this->roleId($this->alpha, 'SUPERVISOR'), array_column($response->json('data.1.roles'), 'id'));
    }

    public function test_one_address_is_invited_to_several_organizations(): void
    {
        Notification::fake();

        $this->postJson('/api/invitations', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'SUPERVISOR')]],
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'SUPERVISOR')]],
        ]))->assertCreated()
            ->assertJsonPath('data.roles.0.code', 'SUPERVISOR')
            ->assertJsonPath('data.job_title', 'Staf Gudang')
            ->assertJsonPath('meta.organization_ids', [$this->alpha->id, $this->beta->id]);

        // Nothing but invitations: no account, no membership, one email per organization.
        $this->assertFalse(User::query()->where('email', 'sari@grup.test')->exists());
        $invitations = OrganizationInvitation::query()->where('email', 'sari@grup.test')->get()->keyBy('organization_id');
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $invitations->keys()->all());
        $this->assertSame([$this->roleId($this->beta, 'SUPERVISOR')], $invitations[$this->beta->id]->role_ids);
        $this->assertSame('Staf Gudang', $invitations[$this->beta->id]->job_title);
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.created')->where('organization_id', $this->beta->id)->exists());
        Notification::assertSentOnDemandTimes(OrganizationInvitationNotification::class, 2);
    }

    public function test_invitations_can_be_sent_to_other_organizations_only(): void
    {
        $this->postJson('/api/invitations', $this->payload([
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'SUPERVISOR')]],
        ]))->assertCreated()->assertJsonPath('data', null)->assertJsonPath('meta.organization_ids', [$this->beta->id]);

        $this->assertSame([$this->beta->id], OrganizationInvitation::query()->where('email', 'sari@grup.test')->pluck('organization_id')->all());
    }

    public function test_all_invitations_are_rejected_when_one_organization_is_not_manageable(): void
    {
        $this->assertErrorCode($this->postJson('/api/invitations', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'SUPERVISOR')]],
            ['organization_id' => $this->gamma->id, 'role_ids' => [$this->roleId($this->gamma, 'SUPERVISOR')]],
        ])), 403, 'ORGANIZATION_ACCESS_DENIED');

        $this->assertFalse(OrganizationInvitation::query()->where('email', 'sari@grup.test')->exists());
    }

    public function test_a_failing_organization_rolls_back_every_invitation(): void
    {
        Notification::fake();

        // A role of ALPHA cannot be assigned in BETA.
        $response = $this->postJson('/api/invitations', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'SUPERVISOR')]],
            ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->alpha, 'SUPERVISOR')]],
        ]));

        $this->assertErrorCode($response, 422, 'INVALID_ROLE');
        $this->assertStringStartsWith('Organisasi BETA:', $response->json('error.message'));
        $this->assertFalse(OrganizationInvitation::query()->where('email', 'sari@grup.test')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'invitation.created')->exists());
        // Emails go out only after commit, so the rolled-back ALPHA invitation sent nothing.
        Notification::assertNothingSent();
    }

    public function test_membership_validation_errors_use_standard_envelope(): void
    {
        $this->postJson('/api/invitations', $this->payload([
            ['organization_id' => $this->alpha->id, 'role_ids' => []],
            ['organization_id' => $this->alpha->id, 'role_ids' => [$this->roleId($this->alpha, 'SUPERVISOR')]],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['memberships.0.role_ids', 'memberships.1.organization_id']]]]);
    }

    public function test_member_detail_lists_organization_access_and_invites_to_another(): void
    {
        $member = $this->createTenantUser($this->alpha, ['SUPERVISOR']);
        $grant = ['organization_id' => $this->beta->id, 'role_ids' => [$this->roleId($this->beta, 'SUPERVISOR')]];

        $this->getJson("/api/users/{$member->id}/organizations")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->alpha->id)
            ->assertJsonPath('data.0.roles.0.code', 'SUPERVISOR');

        // An invitation to BETA, not a membership: the member decides whether to join.
        $this->postJson("/api/users/{$member->id}/organizations", $grant)->assertCreated()
            ->assertJsonPath('data.organization_id', $this->beta->id)
            ->assertJsonPath('data.email', $member->email)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.roles.0.code', 'SUPERVISOR');
        $this->assertSame([$this->alpha->id], $member->memberships()->pluck('organization_id')->all());
        $this->getJson("/api/users/{$member->id}/organizations")->assertOk()->assertJsonCount(1, 'data');

        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/organizations", $grant), 409, 'INVITATION_PENDING');
        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/organizations", [
            'organization_id' => $this->gamma->id, 'role_ids' => [$this->roleId($this->gamma, 'SUPERVISOR')],
        ]), 403, 'ORGANIZATION_ACCESS_DENIED');

        // Members of the target organization are not invited again.
        $this->addMembership($member, $this->beta, 'SUPERVISOR');
        $this->assertErrorCode($this->postJson("/api/users/{$member->id}/organizations", $grant), 409, 'ALREADY_MEMBER');
    }

    public function test_member_access_list_hides_organizations_the_actor_cannot_view(): void
    {
        $member = $this->createTenantUser($this->alpha, ['SUPERVISOR']);
        ['organization' => $delta] = $this->createOrganization('DELTA');
        $this->addMembership($member, $delta, 'SUPERVISOR');

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
            'email' => 'sari@grup.test',
            'job_title' => 'Staf Gudang',
            'memberships' => $memberships,
        ];
    }
}
