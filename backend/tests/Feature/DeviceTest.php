<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Device;
use App\Models\DeviceBinding;
use App\Models\DeviceType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    protected function actingAsProjectUser(Project $project, string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($project->organization_id);
        $user->projects()->attach($project->id);
        $user->assignRole($roleSlug, $project->organization_id, $project->id);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function tenantHeaders(Project $project): array
    {
        return [
            'X-Organization-Id' => $project->organization_id,
            'X-Project-Id' => $project->id,
        ];
    }

    public function test_user_can_create_device(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson('/api/v1/devices', [
                'device_type_id' => $deviceType->id,
                'serial_number' => 'DEV-001',
                'name' => 'Test Device',
                'status' => 'offline',
            ])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'serial_number' => 'DEV-001',
                    'name' => 'Test Device',
                    'status' => 'offline',
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'serial_number' => 'DEV-001',
            'name' => 'Test Device',
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_user_can_view_devices(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        // Create device in different project - should not be visible
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $otherProject->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson('/api/v1/devices')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(1, 'data');
    }

    public function test_tenant_isolation_for_devices(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        // Create device in current project
        Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        // Create device in different organization
        $otherOrg = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $otherOrg->id]);
        Device::factory()->create([
            'organization_id' => $otherOrg->id,
            'project_id' => $otherProject->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson('/api/v1/devices')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_view_single_device(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->getJson("/api/v1/devices/{$device->system_id}")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'system_id' => $device->system_id,
                    'name' => $device->name,
                ],
            ]);
    }

    public function test_user_can_update_device(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->putJson("/api/v1/devices/{$device->system_id}", [
                'name' => 'Updated Device Name',
                'status' => 'online',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Device Name',
                    'status' => 'online',
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Updated Device Name',
            'status' => 'online',
        ]);
    }

    public function test_user_can_delete_device(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->deleteJson("/api/v1/devices/{$device->system_id}")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('devices', [
            'id' => $device->id,
        ]);
    }

    public function test_user_can_bind_device_to_asset(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/devices/{$device->system_id}/bind", [
                'asset_id' => $asset->id,
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'device_id' => $device->id,
                    'asset_id' => $asset->id,
                ],
            ]);

        $this->assertDatabaseHas('device_bindings', [
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'unbound_at' => null,
        ]);
    }

    public function test_device_can_be_bound_by_asset_system_id_within_its_project_only(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);
        $asset = Asset::factory()->create(['organization_id' => $organization->id, 'project_id' => $project->id]);
        $foreignAsset = Asset::factory()->create(['organization_id' => $organization->id, 'project_id' => $otherProject->id]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/devices/{$device->system_id}/bind", ['asset_system_id' => $foreignAsset->system_id])
            ->assertStatus(422);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/devices/{$device->system_id}/bind", ['asset_system_id' => $asset->system_id])
            ->assertStatus(200)
            ->assertJsonPath('data.asset_system_id', $asset->system_id);

        $this->assertDatabaseHas('device_bindings', ['device_id' => $device->id, 'asset_id' => $asset->id, 'unbound_at' => null]);
    }

    public function test_user_can_unbind_device(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $asset = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        DeviceBinding::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'bound_at' => now(),
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/devices/{$device->system_id}/unbind")
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('device_bindings', [
            'device_id' => $device->id,
            'asset_id' => $asset->id,
        ]);

        $binding = DeviceBinding::where('device_id', $device->id)
            ->where('asset_id', $asset->id)
            ->first();

        $this->assertNotNull($binding->unbound_at);
    }

    public function test_device_status_update(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
            'status' => 'offline',
        ]);

        $this->withHeaders($this->tenantHeaders($project))
            ->putJson("/api/v1/devices/{$device->system_id}/status", [
                'status' => 'online',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'online',
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'status' => 'online',
        ]);
    }

    public function test_device_system_id_is_generated_automatically(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $this->assertNotNull($device->system_id);
        $this->assertStringStartsWith('DEV-', $device->system_id);
    }

    public function test_device_binding_unbinds_previous_asset(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $deviceType = DeviceType::factory()->create();
        $user = $this->actingAsProjectUser($project, 'project-admin');

        $device = Device::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'device_type_id' => $deviceType->id,
        ]);

        $asset1 = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $asset2 = Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        // Bind to first asset
        DeviceBinding::create([
            'device_id' => $device->id,
            'asset_id' => $asset1->id,
            'project_id' => $project->id,
            'bound_at' => now(),
        ]);

        // Rebinding a bound device needs an explicit replace (hardware swap)
        $this->withHeaders($this->tenantHeaders($project))
            ->postJson("/api/v1/devices/{$device->system_id}/bind", [
                'asset_id' => $asset2->id,
                'replace' => true,
            ])
            ->assertStatus(200);

        // First binding should be unbound
        $firstBinding = DeviceBinding::where('device_id', $device->id)
            ->where('asset_id', $asset1->id)
            ->first();

        $this->assertNotNull($firstBinding->unbound_at);

        // Second binding should be active
        $secondBinding = DeviceBinding::where('device_id', $device->id)
            ->where('asset_id', $asset2->id)
            ->first();

        $this->assertNull($secondBinding->unbound_at);
    }
}
