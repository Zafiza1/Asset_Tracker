<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\DeviceType;
use App\Models\EventLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardHealthAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    protected function projectUser(Project $project, string $role = 'project-admin'): User
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

    public function test_health_reports_component_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('checks.database.status', 'healthy')
            ->assertJsonStructure(['status', 'timestamp', 'checks' => ['database', 'redis', 'queue']]);
    }

    public function test_dashboard_counts_offline_only_for_tracked_assets(): void
    {
        $project = Project::factory()->create();
        $this->projectUser($project, 'viewer');
        $make = fn (array $attributes = []) => Asset::factory()->create(array_merge([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'status' => 'active',
        ], $attributes));

        $make();                                             // untracked
        $seen = $make(['last_seen_at' => now()]);            // tracked, online
        $stale = $make(['last_seen_at' => now()->subDay()]); // tracked, offline
        $make(['status' => 'retired']);

        $type = DeviceType::factory()->create();
        foreach ([$seen, $stale] as $i => $asset) {
            $device = Device::factory()->create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'device_type_id' => $type->id,
                'serial_number' => "TAG-{$i}",
            ]);
            DeviceBinding::create([
                'device_id' => $device->id, 'asset_id' => $asset->id,
                'project_id' => $project->id, 'bound_at' => now(),
            ]);
        }

        $this->withHeaders($this->headers($project))->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.totals.assets', 4)
            ->assertJsonPath('data.totals.active', 3)
            ->assertJsonPath('data.totals.tracked', 2)
            ->assertJsonPath('data.totals.offline', 1)
            ->assertJsonPath('data.by_status.retired', 1);
    }

    public function test_dashboard_is_project_isolated(): void
    {
        $mine = Project::factory()->create();
        $theirs = Project::factory()->create();
        Asset::factory()->count(3)->create(['organization_id' => $theirs->organization_id, 'project_id' => $theirs->id]);
        $this->projectUser($mine, 'viewer');

        $this->withHeaders($this->headers($mine))->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.totals.assets', 0);

        $this->withHeaders($this->headers($theirs))->getJson('/api/v1/dashboard')
            ->assertStatus(403);
    }

    public function test_audit_logs_never_include_other_tenants(): void
    {
        $mine = Project::factory()->create();
        $theirs = Project::factory()->create();
        $this->projectUser($mine);

        ActivityLog::create([
            'organization_id' => $theirs->organization_id, 'project_id' => $theirs->id,
            'action' => 'create', 'resource_type' => 'Asset', 'occurred_at' => now(),
        ]);
        $foreignEvent = EventLog::create([
            'organization_id' => $theirs->organization_id, 'project_id' => $theirs->id,
            'event_type' => 'asset.detected', 'source' => 'rfid', 'payload' => [],
            'occurred_at' => now(), 'status' => 'processed',
        ]);
        $own = ActivityLog::create([
            'organization_id' => $mine->organization_id, 'project_id' => $mine->id,
            'action' => 'update', 'resource_type' => 'Asset', 'occurred_at' => now(),
        ]);

        $this->withHeaders($this->headers($mine))->getJson('/api/v1/audit/activity-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->withHeaders($this->headers($mine))->getJson('/api/v1/audit/event-logs')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->headers($mine))->getJson("/api/v1/audit/event-logs/{$foreignEvent->id}")
            ->assertStatus(404);

        $this->withHeaders($this->headers($mine))->getJson('/api/v1/audit/stats')
            ->assertOk()
            ->assertJsonPath('activity_logs.total', 1)
            ->assertJsonPath('event_logs.total', 0);
    }

    public function test_asset_detail_includes_bound_devices_and_activity(): void
    {
        $project = Project::factory()->create();
        $this->projectUser($project);

        $created = $this->withHeaders($this->headers($project))
            ->postJson('/api/v1/assets', ['serial_number' => 'C2H2-3KG-00001', 'name' => 'Cylinder'])
            ->assertStatus(201);
        $asset = Asset::where('system_id', $created->json('data.system_id'))->first();

        $device = Device::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'device_type_id' => DeviceType::factory()->create()->id,
            'serial_number' => 'TAG-00001',
        ]);
        DeviceBinding::create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'project_id' => $project->id, 'bound_at' => now(),
        ]);

        $this->withHeaders($this->headers($project))->getJson("/api/v1/assets/{$asset->system_id}")
            ->assertOk()
            ->assertJsonPath('data.devices.0.serial_number', 'TAG-00001');

        $this->withHeaders($this->headers($project))->getJson("/api/v1/assets/{$asset->system_id}/activity")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'create');
    }
}
