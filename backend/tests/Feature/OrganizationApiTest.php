<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    /**
     * An organization whose owner is returned, created through the API.
     *
     * @return array{0: Organization, 1: User}
     */
    protected function organizationWithOwner(string $name = 'Acme Corp'): array
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/v1/organizations', ['name' => $name])->assertCreated()->json('data.id');

        return [Organization::findOrFail($id), $owner];
    }

    protected function orgMember(Organization $organization, ?string $roleSlug = null): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'member']);

        if ($roleSlug) {
            $user->assignRole($roleSlug, $organization->id);
        }

        return $user;
    }

    public function test_user_can_create_organization_and_becomes_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'PT Example Logistics',
            'email' => 'ops@example.com',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'pt-example-logistics')
            ->assertJsonPath('data.status', 'active');

        $organizationId = $response->json('data.id');

        $this->assertTrue($user->canAccessOrganization($organizationId));
        $this->assertTrue($user->hasRole('organization-owner', $organizationId));
        $this->assertSame($organizationId, $user->fresh()->default_organization_id);

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.membership', 'owner');
    }

    public function test_self_service_can_be_disabled(): void
    {
        config(['platform.self_service_organizations' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/organizations', ['name' => 'Nope'])->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/organizations', ['name' => 'Allowed'])->assertCreated();
    }

    public function test_slug_is_generated_uniquely_and_validated(): void
    {
        $this->organizationWithOwner('Acme Corp');

        $this->postJson('/api/v1/organizations', ['name' => 'Acme Corp'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-corp-2');

        $this->postJson('/api/v1/organizations', ['name' => 'Other', 'slug' => 'acme-corp'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['slug']]);

        $this->postJson('/api/v1/organizations', ['name' => 'Other', 'slug' => 'Not A Slug'])
            ->assertStatus(422);
    }

    public function test_users_only_see_their_own_organizations(): void
    {
        [$organizationA] = $this->organizationWithOwner('Org A');
        [$organizationB] = $this->organizationWithOwner('Org B');

        // Acting as B's owner now.
        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $organizationB->id);

        $this->getJson("/api/v1/organizations/{$organizationA->id}")->assertForbidden();
        $this->putJson("/api/v1/organizations/{$organizationA->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organizationA->id}/members")->assertForbidden();
    }

    public function test_default_context_does_not_hide_addressed_organization(): void
    {
        [$organizationA, $owner] = $this->organizationWithOwner('Org A');
        $organizationB = Organization::factory()->create();
        $owner->organizations()->attach($organizationB->id, ['role' => 'member']);
        $owner->assignRole('viewer', $organizationB->id);

        // default_organization_id is A; B must still be reachable by URL.
        $this->getJson("/api/v1/organizations/{$organizationB->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $organizationB->id);
        $this->getJson("/api/v1/organizations/{$organizationA->id}")->assertOk();
    }

    public function test_owner_can_update_but_only_platform_admin_can_delete(): void
    {
        [$organization] = $this->organizationWithOwner();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'Acme Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Renamed');

        $this->deleteJson("/api/v1/organizations/{$organization->id}")->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/organizations/{$organization->id}")->assertOk();
        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_viewer_member_cannot_update_organization(): void
    {
        [$organization] = $this->organizationWithOwner();
        Sanctum::actingAs($this->orgMember($organization, 'viewer'));

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_owner_manages_members(): void
    {
        [$organization] = $this->organizationWithOwner();
        $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => 'newcomer@example.com',
            'role' => 'manager',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'newcomer@example.com')
            ->assertJsonPath('data.membership', 'member')
            ->assertJsonPath('data.roles', ['manager']);

        $this->assertTrue($newcomer->hasRole('manager', $organization->id));

        $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => 'newcomer@example.com'])
            ->assertStatus(409);
        $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => 'nobody@example.com'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => User::factory()->create()->email,
            'role' => 'platform-admin',
        ])->assertStatus(422);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$newcomer->id}", ['role' => 'viewer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['viewer']);
        $this->assertFalse($newcomer->hasRole('manager', $organization->id));

        $this->getJson("/api/v1/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$newcomer->id}")->assertOk();
        $this->assertFalse($newcomer->canAccessOrganization($organization->id));
    }

    public function test_members_cannot_grant_or_manage_above_their_own_level(): void
    {
        [$organization, $owner] = $this->organizationWithOwner();

        // A manager (level 60) who was also given member management.
        $manager = $this->orgMember($organization, 'manager');
        $manager->givePermission('organization.manage-users', $organization->id);
        Sanctum::actingAs($manager);

        $candidate = User::factory()->create();

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => $candidate->email,
            'role' => 'organization-owner',
        ])->assertForbidden();

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => $candidate->email,
            'role' => 'viewer',
        ])->assertCreated();

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}", ['role' => 'viewer'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}")
            ->assertForbidden();
        $this->assertTrue($owner->hasRole('organization-owner', $organization->id));
    }

    public function test_member_without_manage_permission_cannot_add_members(): void
    {
        [$organization] = $this->organizationWithOwner();
        Sanctum::actingAs($this->orgMember($organization, 'manager'));

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => User::factory()->create()->email,
        ])->assertForbidden();
    }

    public function test_organization_keeps_at_least_one_owner(): void
    {
        [$organization, $owner] = $this->organizationWithOwner();

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}")
            ->assertStatus(409);
        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}", ['role' => 'viewer'])
            ->assertStatus(409);

        $coOwner = User::factory()->create();
        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'email' => $coOwner->email,
            'role' => 'organization-owner',
        ])->assertCreated()->assertJsonPath('data.membership', 'owner');

        // Now the original owner may leave.
        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}")->assertOk();
        $this->assertFalse($owner->canAccessOrganization($organization->id));
    }

    public function test_removing_a_member_revokes_their_project_access(): void
    {
        [$organization] = $this->organizationWithOwner();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $member = $this->orgMember($organization, 'viewer');
        $member->projects()->attach($project->id, ['role' => 'operator']);
        $member->assignRole('operator', $organization->id, $project->id);
        $member->forceFill(['default_organization_id' => $organization->id, 'default_project_id' => $project->id])->save();

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$member->id}")->assertOk();

        $member->refresh();
        $this->assertFalse($member->canAccessProject($project->id));
        $this->assertFalse($member->hasPermission('asset.view', $organization->id, $project->id));
        $this->assertDatabaseMissing('user_roles', ['user_id' => $member->id]);
        $this->assertNull($member->default_organization_id);
        $this->assertNull($member->default_project_id);
    }

    public function test_members_cannot_change_their_own_role(): void
    {
        [$organization, $owner] = $this->organizationWithOwner();

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$owner->id}", ['role' => 'organization-owner'])
            ->assertStatus(409);
    }
}
