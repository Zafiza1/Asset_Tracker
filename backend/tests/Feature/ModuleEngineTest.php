<?php

namespace Tests\Feature;

use App\Events\ModuleLifecycleChanged;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Modules\BaseModule;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ModuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected ModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Fixture catalog, independent of config/modules.php.
        $this->registry = app(ModuleRegistry::class);
        $this->registry->register([
            'slug' => 'asset', 'name' => 'Asset', 'category' => 'core', 'is_core' => true,
            'versions' => [['version' => '1.0.0']],
        ]);
        $this->registry->register([
            'slug' => 'customer', 'name' => 'Customer',
            'versions' => [['version' => '1.0.0', 'permissions' => ['customer.view']]],
        ]);
        $this->registry->register([
            'slug' => 'delivery', 'name' => 'Delivery',
            'versions' => [[
                'version' => '1.0.0',
                'dependencies' => ['customer' => '^1.0', 'asset' => '>=1.0.0'],
            ]],
        ]);
        $this->registerMaintenanceV1();
    }

    protected function registerMaintenanceV1(): void
    {
        $this->registry->register([
            'slug' => 'maintenance', 'name' => 'Maintenance', 'category' => 'tracking',
            'versions' => [[
                'version' => '1.0.0',
                'dependencies' => ['asset' => '>=1.0.0'],
                'config_schema' => [
                    'interval_days' => ['type' => 'integer', 'default' => 30, 'min' => 1],
                    'mode' => ['type' => 'string', 'options' => ['manual', 'auto'], 'default' => 'manual'],
                ],
            ]],
        ]);
    }

    protected function registerMaintenanceV2(): void
    {
        $this->registry->register([
            'slug' => 'maintenance', 'name' => 'Maintenance', 'category' => 'tracking',
            'versions' => [[
                'version' => '2.0.0',
                'dependencies' => ['asset' => '>=1.0.0'],
                'config_schema' => [
                    'interval_days' => ['type' => 'integer', 'default' => 30, 'min' => 1],
                    'notify' => ['type' => 'boolean', 'default' => true],
                ],
            ]],
        ]);
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

    public function test_catalog_lists_modules_with_versions(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $response = $this->getJson('/api/v1/modules?search=maint', $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'maintenance')
            ->assertJsonPath('data.0.latest_version', '1.0.0');

        $this->assertArrayHasKey('interval_days', $response->json('data.0.versions.0.config_schema'));

        $this->getJson('/api/v1/modules/delivery', $this->tenantHeaders($project))
            ->assertOk()
            ->assertJsonPath('data.versions.0.dependencies.customer', '^1.0');
    }

    public function test_full_module_lifecycle(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'installed')
            ->assertJsonPath('data.version', '1.0.0')
            ->assertJsonPath('data.configuration.interval_days', 30);

        $this->putJson('/api/v1/project-modules/maintenance/configuration', [
            'configuration' => ['interval_days' => 14],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'configured')
            ->assertJsonPath('data.configuration.interval_days', 14)
            ->assertJsonPath('data.configuration.mode', 'manual');

        $this->postJson('/api/v1/project-modules/maintenance/enable', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled');
        $this->assertTrue($project->hasModuleEnabled('maintenance'));

        $this->postJson('/api/v1/project-modules/maintenance/disable', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');
        $this->assertFalse($project->hasModuleEnabled('maintenance'));

        $this->deleteJson('/api/v1/project-modules/maintenance', [], $headers)->assertOk();

        $this->assertDatabaseHas('project_modules', ['project_id' => $project->id, 'status' => 'uninstalled']);
        $this->getJson('/api/v1/project-modules', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/project-modules/maintenance', $headers)->assertNotFound();
    }

    public function test_module_can_be_reinstalled_after_uninstall(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->deleteJson('/api/v1/project-modules/customer', [], $headers)->assertOk();
        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'installed');

        $this->assertSame(1, ProjectModule::where('project_id', $project->id)->count());
    }

    public function test_installing_twice_is_rejected(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertStatus(409);
    }

    public function test_enabled_module_must_be_disabled_before_uninstall(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/customer/enable', [], $headers)->assertOk();
        $this->deleteJson('/api/v1/project-modules/customer', [], $headers)->assertStatus(409);
    }

    public function test_core_modules_cannot_be_installed_and_are_always_enabled(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');

        $this->postJson('/api/v1/project-modules', ['module' => 'asset'], $this->tenantHeaders($project))
            ->assertStatus(409);

        $this->assertTrue($project->hasModuleEnabled('asset'));
    }

    public function test_unknown_module_returns_not_found(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');

        $this->postJson('/api/v1/project-modules', ['module' => 'does-not-exist'], $this->tenantHeaders($project))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_dependencies_are_enforced(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        // delivery requires customer to be installed...
        $this->postJson('/api/v1/project-modules', ['module' => 'delivery'], $headers)->assertStatus(409);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules', ['module' => 'delivery'], $headers)->assertCreated();

        // ...and enabled before delivery can be enabled.
        $this->postJson('/api/v1/project-modules/delivery/enable', [], $headers)->assertStatus(409);
        $this->postJson('/api/v1/project-modules/customer/enable', [], $headers)->assertOk();
        $this->postJson('/api/v1/project-modules/delivery/enable', [], $headers)->assertOk();

        // A module that others depend on cannot be disabled or removed.
        $this->postJson('/api/v1/project-modules/customer/disable', [], $headers)->assertStatus(409);
        $this->postJson('/api/v1/project-modules/delivery/disable', [], $headers)->assertOk();
        $this->postJson('/api/v1/project-modules/customer/disable', [], $headers)->assertOk();
        $this->deleteJson('/api/v1/project-modules/customer', [], $headers)->assertStatus(409);
        $this->deleteJson('/api/v1/project-modules/delivery', [], $headers)->assertOk();
        $this->deleteJson('/api/v1/project-modules/customer', [], $headers)->assertOk();
    }

    public function test_configuration_is_validated_against_schema(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', [
            'module' => 'maintenance',
            'configuration' => ['unknown_setting' => true],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['configuration.unknown_setting']]);

        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $headers)->assertCreated();

        $this->putJson('/api/v1/project-modules/maintenance/configuration', [
            'configuration' => ['interval_days' => 0, 'mode' => 'sometimes'],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['configuration.interval_days', 'configuration.mode']]);

        $this->putJson('/api/v1/project-modules/maintenance/configuration', [
            'configuration' => ['interval_days' => 'weekly'],
        ], $headers)->assertStatus(422);
    }

    public function test_required_settings_must_be_configured_before_enable(): void
    {
        $this->registry->register([
            'slug' => 'rental', 'name' => 'Rental',
            'versions' => [[
                'version' => '1.0.0',
                'config_schema' => ['currency' => ['type' => 'string', 'required' => true]],
            ]],
        ]);

        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'rental'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/rental/enable', [], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['configuration.currency']]);

        $this->putJson('/api/v1/project-modules/rental/configuration', [
            'configuration' => ['currency' => 'IDR'],
        ], $headers)->assertOk();
        $this->postJson('/api/v1/project-modules/rental/enable', [], $headers)->assertOk();

        // An enabled module cannot lose a required setting.
        $this->putJson('/api/v1/project-modules/rental/configuration', [
            'configuration' => ['currency' => null],
        ], $headers)->assertStatus(422);
    }

    public function test_project_stays_on_its_version_when_a_new_version_is_published(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/maintenance/enable', [], $headers)->assertOk();

        $this->registerMaintenanceV2();

        $this->getJson('/api/v1/project-modules/maintenance', $headers)
            ->assertOk()
            ->assertJsonPath('data.version', '1.0.0')
            ->assertJsonPath('data.latest_version', '2.0.0')
            ->assertJsonPath('data.upgrade_available', true)
            ->assertJsonPath('data.status', 'enabled');

        // New installs in other projects get the latest version.
        $other = Project::factory()->create();
        $this->actingAsProjectUser($other, 'module-manager');
        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $this->tenantHeaders($other))
            ->assertCreated()
            ->assertJsonPath('data.version', '2.0.0');

        // ...unless they pin a version.
        $pinned = Project::factory()->create();
        $this->actingAsProjectUser($pinned, 'module-manager');
        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance', 'version' => '1.0.0'], $this->tenantHeaders($pinned))
            ->assertCreated()
            ->assertJsonPath('data.version', '1.0.0');
    }

    public function test_upgrade_moves_to_newer_version_and_migrates_configuration(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', [
            'module' => 'maintenance',
            'configuration' => ['interval_days' => 7, 'mode' => 'auto'],
        ], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/maintenance/enable', [], $headers)->assertOk();

        $this->registerMaintenanceV2();

        $response = $this->postJson('/api/v1/project-modules/maintenance/upgrade', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonPath('data.status', 'enabled')
            ->assertJsonPath('data.configuration.interval_days', 7)
            ->assertJsonPath('data.configuration.notify', true);

        // "mode" no longer exists in 2.0.0's schema.
        $this->assertArrayNotHasKey('mode', $response->json('data.configuration'));

        $this->postJson('/api/v1/project-modules/maintenance/upgrade', ['version' => '1.0.0'], $headers)
            ->assertStatus(409);
    }

    public function test_upgrade_is_refused_when_it_would_break_a_dependent_module(): void
    {
        $this->registry->register([
            'slug' => 'customer', 'name' => 'Customer',
            'versions' => [['version' => '2.0.0']],
        ]);

        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer', 'version' => '1.0.0'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules', ['module' => 'delivery'], $headers)->assertCreated();

        // delivery 1.0.0 requires customer ^1.0
        $this->postJson('/api/v1/project-modules/customer/upgrade', ['version' => '2.0.0'], $headers)
            ->assertStatus(409);
    }

    public function test_deprecated_versions_keep_running_but_cannot_be_installed(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/maintenance/enable', [], $headers)->assertOk();

        $this->registry->register([
            'slug' => 'maintenance', 'name' => 'Maintenance',
            'versions' => [['version' => '1.0.0', 'status' => 'deprecated']],
        ]);

        $this->assertTrue($project->hasModuleEnabled('maintenance'));

        $other = Project::factory()->create();
        $this->actingAsProjectUser($other, 'module-manager');
        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance', 'version' => '1.0.0'], $this->tenantHeaders($other))
            ->assertStatus(409);
    }

    public function test_viewer_cannot_manage_modules(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $this->tenantHeaders($project))
            ->assertForbidden();
    }

    public function test_project_admin_can_enable_but_not_install(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $this->tenantHeaders($project))
            ->assertCreated();

        $this->actingAsProjectUser($project, 'project-admin');

        $this->postJson('/api/v1/project-modules', ['module' => 'maintenance'], $this->tenantHeaders($project))
            ->assertForbidden();
        $this->postJson('/api/v1/project-modules/customer/enable', [], $this->tenantHeaders($project))
            ->assertOk();
        $this->deleteJson('/api/v1/project-modules/customer', [], $this->tenantHeaders($project))
            ->assertForbidden();
    }

    public function test_modules_are_isolated_between_projects(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $this->actingAsProjectUser($projectA, 'module-manager');
        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $this->tenantHeaders($projectA))
            ->assertCreated();
        $this->postJson('/api/v1/project-modules/customer/enable', [], $this->tenantHeaders($projectA))
            ->assertOk();

        $this->actingAsProjectUser($projectB, 'module-manager');

        // B sees nothing installed, and cannot reach A's installation.
        $this->getJson('/api/v1/project-modules', $this->tenantHeaders($projectB))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/project-modules/customer/disable', [], $this->tenantHeaders($projectB))
            ->assertNotFound();
        $this->getJson('/api/v1/project-modules', $this->tenantHeaders($projectA))
            ->assertForbidden();

        $this->assertTrue($projectA->hasModuleEnabled('customer'));
        $this->assertFalse($projectB->hasModuleEnabled('customer'));
    }

    public function test_module_middleware_gates_routes_on_enabled_modules(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:customer'])
            ->get('/api/test/customer-only', fn () => response()->json(['success' => true]));

        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->getJson('/api/test/customer-only', $headers)->assertForbidden();

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->getJson('/api/test/customer-only', $headers)->assertForbidden();

        $this->postJson('/api/v1/project-modules/customer/enable', [], $headers)->assertOk();
        $this->getJson('/api/test/customer-only', $headers)->assertOk();
    }

    public function test_lifecycle_events_are_dispatched(): void
    {
        Event::fake([ModuleLifecycleChanged::class]);

        $project = Project::factory()->create();
        $user = $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/customer/enable', [], $headers)->assertOk();

        Event::assertDispatched(ModuleLifecycleChanged::class, fn ($e) => $e->event === 'project.module.installed');
        Event::assertDispatched(ModuleLifecycleChanged::class, function (ModuleLifecycleChanged $e) use ($project, $user) {
            $payload = $e->payload();

            return $payload['event'] === 'project.module.enabled'
                && $payload['module'] === 'customer'
                && $payload['project_id'] === $project->id
                && $payload['previous_status'] === 'installed'
                && $payload['user_id'] === $user->id;
        });
    }

    public function test_failing_lifecycle_hook_rolls_back_the_transition(): void
    {
        config(['modules.handlers.customer' => FailingEnableModule::class]);

        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'module-manager');
        $headers = $this->tenantHeaders($project);

        $this->postJson('/api/v1/project-modules', ['module' => 'customer'], $headers)->assertCreated();
        $this->postJson('/api/v1/project-modules/customer/enable', [], $headers)->assertServerError();

        $this->assertDatabaseHas('project_modules', ['project_id' => $project->id, 'status' => 'installed']);
    }

    public function test_registering_a_module_version_publishes_its_permissions(): void
    {
        $this->assertDatabaseHas('permissions', ['slug' => 'customer.view', 'module' => 'customer']);
    }
}

class FailingEnableModule extends BaseModule
{
    public function onEnable(ProjectModule $projectModule): void
    {
        throw new RuntimeException('Module refused to enable');
    }
}
