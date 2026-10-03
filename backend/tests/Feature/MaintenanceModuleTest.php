<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Maintenance\Events\MaintenanceCompleted;
use App\Modules\Maintenance\Events\MaintenanceCreated;
use App\Modules\Maintenance\Models\MaintenanceRecord;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Maintenance module is the reference business module: its endpoints only
 * exist while the module is enabled for the project, and it is authorized by
 * the permissions its manifest declares.
 */
class MaintenanceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
        $this->asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $this->project->id,
        ]);
    }

    protected function enableMaintenance(Project $project, array $configuration = []): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'maintenance', null, $configuration));
    }

    protected function actingAsRole(Project $project, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($project->organization_id);
        $user->projects()->attach($project->id);
        $user->assignRole($role, $project->organization_id, $project->id);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function headers(Project $project): array
    {
        return ['X-Organization-Id' => $project->organization_id, 'X-Project-Id' => $project->id];
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');

        $this->getJson('/api/v1/maintenance', $this->headers($this->project))
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $modules = app(ModuleService::class);
        $installed = $modules->install($this->project, 'maintenance');
        $this->getJson('/api/v1/maintenance', $this->headers($this->project))->assertStatus(403);

        $modules->enable($installed);
        $this->getJson('/api/v1/maintenance', $this->headers($this->project))->assertOk();

        $modules->disable($installed->fresh());
        $this->getJson('/api/v1/maintenance', $this->headers($this->project))->assertStatus(403);
    }

    public function test_me_lists_enabled_modules_for_accessible_projects_only(): void
    {
        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'viewer');

        $this->getJson('/api/auth/me', $this->headers($this->project))
            ->assertOk()
            ->assertJsonPath('data.project_modules', ['maintenance']);

        $foreign = Project::factory()->create();
        $this->enableMaintenance($foreign);
        $this->getJson('/api/auth/me', $this->headers($foreign))
            ->assertOk()
            ->assertJsonPath('data.project_modules', []);
    }

    public function test_manager_schedules_updates_and_completes_maintenance(): void
    {
        Event::fake([MaintenanceCreated::class, MaintenanceCompleted::class]);
        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers($this->project);

        $id = $this->postJson('/api/v1/maintenance', [
            'asset_id' => $this->asset->system_id,
            'title' => 'Valve check',
            'type' => 'preventive',
            'scheduled_at' => now()->addWeek()->toIso8601String(),
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.asset_id', $this->asset->id)
            ->json('data.id');
        Event::assertDispatched(MaintenanceCreated::class, fn ($e) => $e->eventType === 'maintenance.created'
            && $e->payload['system_id'] === $this->asset->system_id);

        $this->putJson("/api/v1/maintenance/{$id}", ['status' => 'in_progress'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertNotNull(MaintenanceRecord::find($id)->started_at);

        $this->postJson("/api/v1/maintenance/{$id}/complete", ['notes' => 'Replaced seal', 'cost' => 125.5], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.notes', 'Replaced seal')
            ->assertJsonPath('next', null);
        Event::assertDispatched(MaintenanceCompleted::class);

        // Finished records are read-only.
        $this->putJson("/api/v1/maintenance/{$id}", ['title' => 'Edited'], $headers)->assertStatus(409);
        $this->postJson("/api/v1/maintenance/{$id}/complete", [], $headers)->assertStatus(409);

        $this->getJson("/api/v1/maintenance?asset_id={$this->asset->system_id}&status=completed", $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.asset.system_id', $this->asset->system_id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'maintenance.completed', 'resource_id' => $id]);
    }

    public function test_status_cannot_be_set_to_completed_through_update(): void
    {
        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'manager');
        $record = MaintenanceRecord::create([
            'organization_id' => $this->project->organization_id,
            'project_id' => $this->project->id,
            'asset_id' => $this->asset->id,
            'title' => 'Check',
        ]);

        $this->putJson("/api/v1/maintenance/{$record->id}", ['status' => 'completed'], $this->headers($this->project))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_viewer_can_list_but_not_schedule(): void
    {
        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'viewer');

        $this->getJson('/api/v1/maintenance', $this->headers($this->project))->assertOk();
        $this->postJson('/api/v1/maintenance', [
            'asset_id' => $this->asset->system_id,
            'title' => 'Nope',
        ], $this->headers($this->project))->assertStatus(403);
    }

    public function test_operator_can_complete_but_not_schedule(): void
    {
        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'operator');
        $record = MaintenanceRecord::create([
            'organization_id' => $this->project->organization_id,
            'project_id' => $this->project->id,
            'asset_id' => $this->asset->id,
            'title' => 'Check',
        ]);

        $this->postJson('/api/v1/maintenance', ['asset_id' => $this->asset->system_id, 'title' => 'X'], $this->headers($this->project))
            ->assertStatus(403);
        $this->postJson("/api/v1/maintenance/{$record->id}/complete", [], $this->headers($this->project))
            ->assertOk();
    }

    public function test_records_and_assets_of_another_project_are_isolated(): void
    {
        $other = Project::factory()->create(['organization_id' => $this->project->organization_id]);
        $otherAsset = Asset::factory()->create(['organization_id' => $other->organization_id, 'project_id' => $other->id]);
        $otherRecord = MaintenanceRecord::create([
            'organization_id' => $other->organization_id,
            'project_id' => $other->id,
            'asset_id' => $otherAsset->id,
            'title' => 'Theirs',
        ]);

        $this->enableMaintenance($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers($this->project);

        $this->getJson("/api/v1/maintenance/{$otherRecord->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/maintenance/{$otherRecord->id}/complete", [], $headers)->assertNotFound();
        $this->postJson('/api/v1/maintenance', ['asset_id' => $otherAsset->system_id, 'title' => 'X'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');
        $this->getJson('/api/v1/maintenance', $headers)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_auto_schedule_creates_the_next_maintenance(): void
    {
        $this->enableMaintenance($this->project, ['auto_schedule' => true, 'default_interval_days' => 14]);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers($this->project);

        $id = $this->postJson('/api/v1/maintenance', ['asset_id' => $this->asset->system_id, 'title' => 'Inspection'], $headers)
            ->assertCreated()->json('data.id');

        // Without scheduled_at, the project's default interval applies.
        $this->assertEqualsWithDelta(
            now()->addDays(14)->timestamp,
            MaintenanceRecord::find($id)->scheduled_at->timestamp,
            60
        );

        $next = $this->postJson("/api/v1/maintenance/{$id}/complete", [], $headers)
            ->assertOk()
            ->assertJsonPath('next.status', 'scheduled')
            ->assertJsonPath('next.previous_record_id', $id)
            ->json('next');

        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, strtotime($next['scheduled_at']), 60);
    }
}
