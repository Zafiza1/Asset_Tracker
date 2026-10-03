<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Inspection\Events\InspectionChanged;
use App\Modules\Inspection\Models\Inspection;
use App\Modules\Inspection\Models\InspectionChecklist;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InspectionModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Asset $forklift;

    protected array $forkliftChecks = [
        ['key' => 'brakes', 'label' => 'Brakes', 'type' => 'pass_fail', 'required' => true],
        ['key' => 'hydraulics', 'label' => 'Hydraulics', 'type' => 'pass_fail', 'required' => true],
        ['key' => 'hours', 'label' => 'Engine hours', 'type' => 'number', 'required' => false],
        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'required' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
        $this->forklift = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $this->project->id,
            'asset_type' => 'forklift',
        ]);
    }

    protected function enableInspection(Project $project, array $configuration = []): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'inspection', null, $configuration));
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

    protected function headers(?Project $project = null): array
    {
        $project ??= $this->project;

        return ['X-Organization-Id' => $project->organization_id, 'X-Project-Id' => $project->id];
    }

    protected function checklist(array $attributes = []): InspectionChecklist
    {
        return InspectionChecklist::create(array_merge([
            'organization_id' => $this->project->organization_id,
            'project_id' => $this->project->id,
            'name' => 'Forklift daily check',
            'asset_type' => 'forklift',
            'items' => $this->forkliftChecks,
        ], $attributes));
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');
        $this->getJson('/api/v1/inspections', $this->headers())->assertStatus(403);
        $this->getJson('/api/v1/inspections/checklists', $this->headers())->assertStatus(403);

        $this->enableInspection($this->project);
        $this->getJson('/api/v1/inspections', $this->headers())->assertOk();
    }

    public function test_manager_builds_a_checklist_and_an_inspection_is_scheduled_and_recorded(): void
    {
        Event::fake([InspectionChanged::class]);
        $this->enableInspection($this->project, ['default_interval_days' => 30]);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        $checklistId = $this->postJson('/api/v1/inspections/checklists', [
            'name' => 'Forklift daily check',
            'asset_type' => 'forklift',
            'items' => $this->forkliftChecks,
        ], $headers)->assertCreated()->json('data.id');

        // The forklift checklist is picked automatically.
        $id = $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.checklist.id', $checklistId)
            ->assertJsonCount(4, 'data.checklist.items')
            ->json('data.id');

        $this->postJson("/api/v1/inspections/{$id}/record", [
            'answers' => ['brakes' => true, 'hydraulics' => false, 'hours' => 1520.5, 'remarks' => 'Oil leak at the lift cylinder'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result', 'fail')
            ->assertJsonPath('data.answers.hours', 1520.5)
            ->assertJsonCount(4, 'data.checklist_snapshot');

        $inspection = Inspection::find($id);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $inspection->next_due_at->timestamp, 60);
        Event::assertDispatched(InspectionChanged::class, fn ($e) => $e->eventType === 'inspection.completed'
            && $e->payload['result'] === 'fail' && $e->payload['failed_checks'] === ['Hydraulics']);

        // The next one is due when the last inspection said.
        $next = $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)->assertCreated()->json('data.scheduled_at');
        $this->assertSame($inspection->next_due_at->timestamp, strtotime($next));

        $this->getJson('/api/v1/inspections?result=fail', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'inspection.completed', 'resource_id' => $id]);
    }

    public function test_answers_are_validated_against_the_checklist(): void
    {
        $this->enableInspection($this->project);
        $this->actingAsRole($this->project, 'operator');
        $this->checklist();
        $headers = $this->headers();
        $id = $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/inspections/{$id}/record", [
            'answers' => ['brakes' => 'yes', 'hours' => 'many', 'unknown_check' => true],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.brakes', 'answers.hydraulics', 'answers.hours', 'answers.unknown_check']);

        $this->postJson("/api/v1/inspections/{$id}/record", ['answers' => ['brakes' => true, 'hydraulics' => true]], $headers)
            ->assertOk()->assertJsonPath('data.result', 'pass');

        // Recorded once only.
        $this->postJson("/api/v1/inspections/{$id}/record", ['answers' => ['brakes' => true, 'hydraulics' => true]], $headers)
            ->assertStatus(409);
    }

    public function test_editing_a_checklist_does_not_rewrite_past_inspections(): void
    {
        $this->enableInspection($this->project);
        $this->actingAsRole($this->project, 'manager');
        $checklist = $this->checklist();
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/inspections', [
            'asset_id' => $this->forklift->system_id,
            'answers' => ['brakes' => true, 'hydraulics' => true],
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'completed')->json('data.id');

        $this->putJson("/api/v1/inspections/checklists/{$checklist->id}", [
            'items' => [['key' => 'tyres', 'label' => 'Tyres', 'type' => 'pass_fail', 'required' => true]],
        ], $headers)->assertOk();

        $this->getJson("/api/v1/inspections/{$id}", $headers)
            ->assertOk()
            ->assertJsonCount(4, 'data.checklist_snapshot')
            ->assertJsonPath('data.checklist_snapshot.0.key', 'brakes');
    }

    public function test_checklist_rules(): void
    {
        $this->enableInspection($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        // Required by default, and none exists yet.
        $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('checklist_id');

        // Duplicate keys and unknown item types are refused.
        $this->postJson('/api/v1/inspections/checklists', ['name' => 'X', 'items' => [
            ['key' => 'a', 'label' => 'A', 'type' => 'pass_fail'],
            ['key' => 'a', 'label' => 'B', 'type' => 'pass_fail'],
        ]], $headers)->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/inspections/checklists', ['name' => 'X', 'items' => [
            ['key' => 'a', 'label' => 'A', 'type' => 'photo'],
        ]], $headers)->assertStatus(422)->assertJsonValidationErrors('items.0.type');

        // A checklist for another asset type cannot be used.
        $press = $this->checklist(['name' => 'Press check', 'asset_type' => 'press']);
        $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id, 'checklist_id' => $press->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('checklist_id');
    }

    public function test_without_required_checklist_the_result_is_given_directly(): void
    {
        $this->enableInspection($this->project, ['checklist_required' => false]);
        $this->actingAsRole($this->project, 'operator');
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)
            ->assertCreated()->assertJsonPath('data.checklist_id', null)->json('data.id');

        $this->postJson("/api/v1/inspections/{$id}/record", [], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('result');
        $this->postJson("/api/v1/inspections/{$id}/record", ['result' => 'pass', 'notes' => 'Visual check OK'], $headers)
            ->assertOk()->assertJsonPath('data.result', 'pass');
    }

    public function test_permissions_split_performing_from_managing(): void
    {
        $this->enableInspection($this->project);
        $checklist = $this->checklist();
        $headers = $this->headers();

        // Operators perform inspections but do not manage them.
        $this->actingAsRole($this->project, 'operator');
        $id = $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id], $headers)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/inspections/{$id}", ['status' => 'cancelled'], $headers)->assertStatus(403);
        $this->putJson("/api/v1/inspections/checklists/{$checklist->id}", ['name' => 'Renamed'], $headers)->assertStatus(403);

        // Viewers only read.
        $this->actingAsRole($this->project, 'viewer');
        $this->getJson('/api/v1/inspections/checklists', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/inspections/{$id}/record", ['answers' => ['brakes' => true, 'hydraulics' => true]], $headers)->assertStatus(403);

        // Managers cancel; a cancelled inspection is closed.
        $this->actingAsRole($this->project, 'manager');
        $this->putJson("/api/v1/inspections/{$id}", ['status' => 'cancelled'], $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/inspections/{$id}/record", ['answers' => ['brakes' => true, 'hydraulics' => true]], $headers)->assertStatus(409);
    }

    public function test_other_tenants_are_isolated(): void
    {
        $foreignProject = Project::factory()->create();
        $foreignScope = ['organization_id' => $foreignProject->organization_id, 'project_id' => $foreignProject->id];
        $foreignAsset = Asset::factory()->create($foreignScope + ['asset_type' => 'forklift']);
        $foreignChecklist = InspectionChecklist::create($foreignScope + ['name' => 'Theirs', 'items' => $this->forkliftChecks]);
        $foreignInspection = Inspection::create($foreignScope + ['asset_id' => $foreignAsset->id, 'checklist_id' => $foreignChecklist->id]);

        $this->enableInspection($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $this->checklist();
        $headers = $this->headers();

        $this->getJson("/api/v1/inspections/{$foreignInspection->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/inspections/{$foreignInspection->id}/record", ['answers' => ['brakes' => true, 'hydraulics' => true]], $headers)->assertNotFound();
        $this->putJson("/api/v1/inspections/checklists/{$foreignChecklist->id}", ['name' => 'Hijack'], $headers)->assertNotFound();
        $this->postJson('/api/v1/inspections', ['asset_id' => $foreignAsset->system_id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('asset_id');
        $this->postJson('/api/v1/inspections', ['asset_id' => $this->forklift->system_id, 'checklist_id' => $foreignChecklist->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('checklist_id');
        $this->getJson('/api/v1/inspections/checklists', $headers)->assertJsonCount(1, 'data');
    }
}
