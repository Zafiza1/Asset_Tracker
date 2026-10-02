<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Template;
use App\Models\TemplateModule;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
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

    protected function projectMember(Project $project, string $roleSlug): User
    {
        $user = $this->orgMember($project->organization);
        $user->projects()->attach($project->id, ['role' => $roleSlug]);
        $user->assignRole($roleSlug, $project->organization_id, $project->id);

        return $user;
    }

    public function test_owner_creates_project_from_template_and_becomes_project_admin(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->orgMember($organization, 'organization-owner');
        $template = Template::factory()->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/projects", [
            'name' => 'Cylinder Asset Tracker',
            'template_id' => $template->id,
            'settings' => ['timezone' => 'Asia/Jakarta'],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'cylinder-asset-tracker')
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.template.id', $template->id)
            ->assertJsonPath('data.settings.timezone', 'Asia/Jakarta');

        $projectId = $response->json('data.id');
        $this->assertTrue($owner->canAccessProject($projectId));
        $this->assertTrue($owner->hasRole('project-admin', $organization->id, $projectId));

        $this->getJson("/api/v1/projects/{$projectId}/members")
            ->assertOk()
            ->assertJsonPath('data.0.membership', 'admin')
            ->assertJsonPath('data.0.roles', ['project-admin']);
    }

    public function test_project_from_template_installs_its_modules_at_the_pinned_version(): void
    {
        $registry = app(ModuleRegistry::class);
        $asset = $registry->register(['slug' => 'asset', 'name' => 'Asset', 'category' => 'core', 'is_core' => true, 'versions' => [['version' => '1.0.0']]]);
        $maintenance = $registry->register(['slug' => 'maintenance', 'name' => 'Maintenance', 'versions' => [['version' => '1.2.0'], ['version' => '2.0.0']]]);
        $inspection = $registry->register(['slug' => 'inspection', 'name' => 'Inspection', 'versions' => [['version' => '1.0.0']]]);

        $template = Template::factory()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id, 'version' => '1.0.0']);
        $template->update(['current_version_id' => $version->id]);
        foreach ([[$asset, null, true], [$maintenance, '^1.0', true], [$inspection, null, false]] as $order => [$module, $constraint, $required]) {
            TemplateModule::create(['template_version_id' => $version->id, 'module_id' => $module->id, 'version_constraint' => $constraint, 'required' => $required, 'default_config' => [], 'sort_order' => $order]);
        }

        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->orgMember($organization, 'organization-owner'));

        $projectId = $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'Fleet', 'template_id' => $template->id])
            ->assertCreated()->json('data.id');

        $this->assertSame($version->id, Project::find($projectId)->template_version_id);
        $installed = ProjectModule::with('module', 'moduleVersion')->where('project_id', $projectId)->get()->keyBy('module.slug');
        $this->assertFalse($installed->has('asset'), 'Core modules are never installed per project');
        $this->assertSame('enabled', $installed['maintenance']->status);
        $this->assertSame('1.2.0', $installed['maintenance']->moduleVersion->version, 'The template constraint pins the major version');
        $this->assertSame('installed', $installed['inspection']->status, 'Optional modules wait for the customer to enable them');
    }

    public function test_project_slug_is_unique_within_organization_only(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->orgMember($organization, 'organization-owner'));

        $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'Tracker'])
            ->assertCreated()->assertJsonPath('data.slug', 'tracker');
        $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'Tracker'])
            ->assertCreated()->assertJsonPath('data.slug', 'tracker-2');
        $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'X', 'slug' => 'tracker'])
            ->assertStatus(422);

        $other = Organization::factory()->create();
        Sanctum::actingAs($this->orgMember($other, 'organization-owner'));
        $this->postJson("/api/v1/organizations/{$other->id}/projects", ['name' => 'X', 'slug' => 'tracker'])
            ->assertCreated();
    }

    public function test_unavailable_template_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->orgMember($organization, 'organization-owner'));
        $deprecated = Template::factory()->create(['status' => 'deprecated']);

        $this->postJson("/api/v1/organizations/{$organization->id}/projects", [
            'name' => 'Old',
            'template_id' => $deprecated->id,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['template_id']]);
    }

    public function test_only_authorized_members_can_create_projects(): void
    {
        $organization = Organization::factory()->create();

        Sanctum::actingAs($this->orgMember($organization, 'viewer'));
        $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'P'])->assertForbidden();

        // Holding project.create in another organization is not enough.
        $elsewhere = Organization::factory()->create();
        $outsider = $this->orgMember($elsewhere, 'organization-owner');
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/organizations/{$organization->id}/projects", ['name' => 'P'])->assertForbidden();
    }

    public function test_organization_role_sees_all_projects_members_see_their_own(): void
    {
        $organization = Organization::factory()->create();
        $projectA = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'A']);
        $projectB = Project::factory()->create(['organization_id' => $organization->id, 'name' => 'B']);
        Project::factory()->create(['name' => 'Other org']);

        Sanctum::actingAs($this->orgMember($organization, 'organization-owner'));
        $this->getJson("/api/v1/organizations/{$organization->id}/projects")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->projectMember($projectA, 'operator'));
        $this->getJson("/api/v1/organizations/{$organization->id}/projects")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projectA->id);
        $this->getJson("/api/v1/projects/{$projectB->id}")->assertForbidden();
    }

    public function test_organization_owner_can_work_in_projects_they_did_not_join(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $owner = $this->orgMember($organization, 'organization-owner');
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/projects/{$project->id}")->assertOk();

        $this->postJson('/api/v1/assets', [
            'name' => 'Forklift',
            'serial_number' => 'F-001',
        ], ['X-Organization-Id' => $organization->id, 'X-Project-Id' => $project->id])->assertCreated();
    }

    public function test_outsider_cannot_see_or_change_project(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($this->orgMember(Organization::factory()->create(), 'organization-owner'));

        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$project->id}/members")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$project->organization_id}/projects")->assertForbidden();
    }

    public function test_project_admin_updates_but_cannot_delete(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($this->projectMember($project, 'project-admin'));

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed', 'status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', 'archived');
        $this->putJson("/api/v1/projects/{$project->id}", ['status' => 'deleted'])->assertStatus(422);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_owner_deletes_project(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Sanctum::actingAs($this->orgMember($organization, 'organization-owner'));

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    }

    public function test_project_admin_manages_members(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($this->projectMember($project, 'project-admin'));

        $colleague = $this->orgMember($project->organization);

        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'email' => $colleague->email,
            'role' => 'operator',
        ])->assertCreated()
            ->assertJsonPath('data.membership', 'operator')
            ->assertJsonPath('data.roles', ['operator']);

        $this->assertTrue($colleague->canAccessProject($project->id));
        $this->assertTrue($colleague->hasPermission('movement.create', $project->organization_id, $project->id));

        // Only organization members can join; organization-level roles and
        // roles above the caller's cannot be granted here.
        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'email' => User::factory()->create()->email,
        ])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'email' => $this->orgMember($project->organization)->email,
            'role' => 'organization-owner',
        ])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'email' => $this->orgMember($project->organization)->email,
            'role' => 'module-manager',
        ])->assertCreated();

        $this->putJson("/api/v1/projects/{$project->id}/members/{$colleague->id}", ['role' => 'viewer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['viewer']);
        $this->assertFalse($colleague->hasPermission('movement.create', $project->organization_id, $project->id));

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$colleague->id}")->assertOk();
        $this->assertFalse($colleague->canAccessProject($project->id));
        $this->assertDatabaseMissing('user_roles', ['user_id' => $colleague->id, 'project_id' => $project->id]);
    }

    public function test_project_admin_cannot_manage_organization_owner(): void
    {
        $project = Project::factory()->create();
        $owner = $this->orgMember($project->organization, 'organization-owner');
        $owner->projects()->attach($project->id, ['role' => 'member']);

        Sanctum::actingAs($this->projectMember($project, 'project-admin'));

        $this->putJson("/api/v1/projects/{$project->id}/members/{$owner->id}", ['role' => 'viewer'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$owner->id}")->assertForbidden();
    }

    public function test_member_can_leave_project(): void
    {
        $project = Project::factory()->create();
        $member = $this->projectMember($project, 'viewer');
        Sanctum::actingAs($member);

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertOk();
        $this->assertFalse($member->canAccessProject($project->id));
    }

    public function test_viewer_cannot_add_members(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($this->projectMember($project, 'viewer'));

        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'email' => $this->orgMember($project->organization)->email,
        ])->assertForbidden();
    }
}
