<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run the role and permission seeder
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    public function test_organization_policy_view_any(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $user->organizations()->attach($org->id);

        $this->assertTrue($user->can('viewAny', Organization::class));
    }

    public function test_organization_policy_view(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $user->organizations()->attach($org->id);

        $this->assertTrue($user->can('view', $org));
    }

    public function test_organization_policy_view_unauthorized(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $this->assertFalse($user->can('view', $org));
    }

    public function test_organization_policy_create(): void
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('platform-admin');

        $regularUser = User::factory()->create();

        // Self-service onboarding (config/platform.php) lets anyone create one...
        config(['platform.self_service_organizations' => true]);
        $this->assertTrue($platformAdmin->can('create', Organization::class));
        $this->assertTrue($regularUser->can('create', Organization::class));

        // ...otherwise only platform admins (or a global organization.create grant).
        config(['platform.self_service_organizations' => false]);
        $this->assertTrue($platformAdmin->can('create', Organization::class));
        $this->assertFalse($regularUser->can('create', Organization::class));
    }

    public function test_organization_policy_update(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $user->organizations()->attach($org->id);
        $user->givePermission('organization.update', $org->id);

        $this->assertTrue($user->can('update', $org));
    }

    public function test_organization_policy_delete(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $user->organizations()->attach($org->id);
        $user->givePermission('organization.delete', $org->id);

        $this->assertTrue($user->can('delete', $org));
    }

    public function test_project_policy_view_any(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $user->projects()->attach($project->id);

        $this->assertTrue($user->can('viewAny', Project::class));
    }

    public function test_project_policy_view(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $user->projects()->attach($project->id);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_project_policy_view_unauthorized(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $this->assertFalse($user->can('view', $project));
    }

    public function test_project_policy_create(): void
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('platform-admin');

        $orgOwner = User::factory()->create();
        $org = Organization::factory()->create();
        $orgOwner->organizations()->attach($org->id);
        $orgOwner->assignRole('organization-owner', $org->id);

        $this->assertTrue($platformAdmin->can('create', Project::class));
        $this->assertTrue($orgOwner->can('create', Project::class));
    }

    public function test_project_policy_update(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $user->projects()->attach($project->id);
        $user->givePermission('project.update', $project->organization_id, $project->id);

        $this->assertTrue($user->can('update', $project));
    }

    public function test_project_policy_delete(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $user->projects()->attach($project->id);
        $user->givePermission('project.delete', $project->organization_id, $project->id);

        $this->assertTrue($user->can('delete', $project));
    }

    public function test_user_policy_view_any(): void
    {
        $user = User::factory()->create();
        $user->givePermission('user.view');

        $this->assertTrue($user->can('viewAny', User::class));
    }

    public function test_user_policy_view_own_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('view', $user));
    }

    public function test_user_policy_view_other_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $user->givePermission('user.view');

        $this->assertTrue($user->can('view', $otherUser));
    }

    public function test_user_policy_update_own_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('update', $user));
    }

    public function test_user_policy_delete_other_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $user->givePermission('user.delete');

        $this->assertTrue($user->can('delete', $otherUser));
    }

    public function test_user_policy_cannot_delete_self(): void
    {
        $user = User::factory()->create();
        $user->givePermission('user.delete');

        $this->assertFalse($user->can('delete', $user));
    }

    public function test_platform_admin_bypasses_all_policies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('platform-admin');

        $org = Organization::factory()->create();
        $project = Project::factory()->create();
        $otherUser = User::factory()->create();

        $this->assertTrue($admin->can('view', $org));
        $this->assertTrue($admin->can('update', $org));
        $this->assertTrue($admin->can('delete', $org));
        $this->assertTrue($admin->can('view', $project));
        $this->assertTrue($admin->can('update', $project));
        $this->assertTrue($admin->can('delete', $project));
        $this->assertTrue($admin->can('delete', $otherUser));
    }
}
