<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Inventory\Events\InventoryEvent;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Location $rackA;

    protected Location $dock;

    /** @var array<string, Asset> pallets by serial */
    protected array $pallets = [];

    protected Asset $rack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
        $scope = $this->scope();

        $this->rackA = Location::factory()->create($scope + ['name' => 'Rack Zone A']);
        $this->dock = Location::factory()->create($scope + ['name' => 'Shipping Dock']);

        // Rack Zone A: 3 pallets and 1 rack. Shipping Dock: 1 pallet.
        foreach (['PLT-1' => $this->rackA, 'PLT-2' => $this->rackA, 'PLT-3' => $this->rackA, 'PLT-4' => $this->dock] as $serial => $location) {
            $this->pallets[$serial] = $this->place(Asset::factory()->create($scope + ['serial_number' => $serial, 'asset_type' => 'pallet']), $location);
        }
        $this->rack = $this->place(Asset::factory()->create($scope + ['serial_number' => 'RACK-1', 'asset_type' => 'rack']), $this->rackA);
    }

    protected function scope(?Project $project = null): array
    {
        $project ??= $this->project;

        return ['organization_id' => $project->organization_id, 'project_id' => $project->id];
    }

    protected function place(Asset $asset, Location $location): Asset
    {
        app(MovementService::class)->recordMovement($asset, ['to_location_id' => $location->id]);

        return $asset;
    }

    protected function enableInventory(Project $project, array $configuration = []): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'inventory', null, $configuration));
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

    protected function stockRow(array $rows, int $locationId, ?string $assetType, bool $allTypes = false): ?array
    {
        return collect($rows)->first(fn ($row) => $row['location_id'] === $locationId && $row['asset_type'] === $assetType && $row['all_types'] === $allTypes);
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');
        $this->getJson('/api/v1/inventory/stock', $this->headers())->assertStatus(403);

        $this->enableInventory($this->project);
        $this->getJson('/api/v1/inventory/stock', $this->headers())->assertOk();
    }

    public function test_stock_is_counted_per_location_and_type_with_minimums(): void
    {
        $this->enableInventory($this->project, ['low_stock_threshold' => 2]);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->rackA->id, 'asset_type' => 'pallet', 'min_quantity' => 5], $headers)->assertOk();
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->rackA->id, 'min_quantity' => 3], $headers)->assertOk();
        // A minimum for a type that is not at the location at all.
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->dock->id, 'asset_type' => 'rack', 'min_quantity' => 1], $headers)->assertOk();
        // Setting it again replaces it.
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->rackA->id, 'asset_type' => 'pallet', 'min_quantity' => 4], $headers)->assertOk();
        $this->getJson('/api/v1/inventory/levels', $headers)->assertOk()->assertJsonCount(3, 'data');

        $rows = $this->getJson('/api/v1/inventory/stock', $headers)->assertOk()->json('data');

        $this->assertSame(['quantity' => 3, 'min_quantity' => 4, 'low' => true], array_intersect_key($this->stockRow($rows, $this->rackA->id, 'pallet'), array_flip(['quantity', 'min_quantity', 'low'])));
        // The project threshold applies to groups without their own minimum.
        $this->assertSame(2, $this->stockRow($rows, $this->rackA->id, 'rack')['min_quantity']);
        $this->assertTrue($this->stockRow($rows, $this->rackA->id, 'rack')['low']);
        // Location-wide minimum counts every asset there.
        $this->assertSame(4, $this->stockRow($rows, $this->rackA->id, null, true)['quantity']);
        $this->assertFalse($this->stockRow($rows, $this->rackA->id, null, true)['low']);
        // Minimum without stock shows as an empty, low group.
        $this->assertSame(0, $this->stockRow($rows, $this->dock->id, 'rack')['quantity']);
        $this->assertTrue($this->stockRow($rows, $this->dock->id, 'rack')['low']);

        $this->getJson("/api/v1/inventory/stock?low=1&location_id={$this->rackA->id}", $headers)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_low_stock_is_announced_once_when_a_location_drops_below_its_minimum(): void
    {
        $this->enableInventory($this->project);
        $this->actingAsRole($this->project, 'manager');
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->rackA->id, 'asset_type' => 'pallet', 'min_quantity' => 3], $this->headers())->assertOk();
        Event::fake([InventoryEvent::class]);

        // 3 → 2 pallets: below the minimum, announced.
        $this->postJson("/api/v1/assets/{$this->pallets['PLT-1']->system_id}/movements", ['to_location_id' => $this->dock->id], $this->headers())->assertCreated();
        Event::assertDispatchedTimes(InventoryEvent::class, 1);
        Event::assertDispatched(InventoryEvent::class, fn ($e) => $e->eventType === 'inventory.low_stock'
            && $e->payload['location_id'] === $this->rackA->id && $e->payload['quantity'] === 2 && $e->payload['min_quantity'] === 3);

        // 2 → 1: still low, not announced again. Other types do not count.
        $this->postJson("/api/v1/assets/{$this->pallets['PLT-2']->system_id}/movements", ['to_location_id' => $this->dock->id], $this->headers())->assertCreated();
        $this->postJson("/api/v1/assets/{$this->rack->system_id}/movements", ['to_location_id' => $this->dock->id], $this->headers())->assertCreated();
        Event::assertDispatchedTimes(InventoryEvent::class, 1);
    }

    public function test_stock_count_finds_missing_and_unexpected_assets_and_reconciles(): void
    {
        Event::fake([InventoryEvent::class]);
        $this->enableInventory($this->project);
        $this->actingAsRole($this->project, 'operator');
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->rackA->id], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.summary.expected', 4)
            ->json('data.id');

        // Only one count per location at a time.
        $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->rackA->id], $headers)->assertStatus(409);

        // Found on the rack: PLT-1, PLT-2 (twice), the rack, plus PLT-4 (recorded at the dock)
        // and a foreign tag. PLT-3 is not found.
        $this->postJson("/api/v1/inventory/counts/{$id}/scan", [
            'asset_ids' => [$this->pallets['PLT-1']->system_id, $this->rack->system_id, 'AST-NOT-OURS'],
            'serial_numbers' => ['PLT-2', 'PLT-4'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.scanned', 4)
            ->assertJsonPath('data.unknown', ['AST-NOT-OURS'])
            ->assertJsonPath('data.summary.found', 3)
            ->assertJsonPath('data.summary.unexpected', 1);
        $this->postJson("/api/v1/inventory/counts/{$id}/scan", ['serial_numbers' => ['PLT-2']], $headers)->assertOk();

        $data = $this->postJson("/api/v1/inventory/counts/{$id}/complete", ['reconcile' => true], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.summary', ['expected' => 4, 'found' => 3, 'missing' => 1, 'unexpected' => 1, 'reconciled' => 1])
            ->json('data.items');

        $byOutcome = collect($data)->groupBy('outcome')->map(fn ($items) => $items->pluck('serial_number')->sort()->values()->all());
        $this->assertSame(['PLT-3'], $byOutcome['missing']);
        $this->assertSame(['PLT-4'], $byOutcome['unexpected']);
        $this->assertSame('Shipping Dock', collect($data)->firstWhere('serial_number', 'PLT-4')['recorded_location']);

        // Reconciled through Core: PLT-4 is now on the rack, with an inventory movement.
        $this->assertSame($this->rackA->id, AssetLocation::where('asset_id', $this->pallets['PLT-4']->id)->value('location_id'));
        $this->assertSame('inventory', Movement::where('asset_id', $this->pallets['PLT-4']->id)->latest('id')->value('source'));
        // Missing assets are only reported, never moved.
        $this->assertSame($this->rackA->id, AssetLocation::where('asset_id', $this->pallets['PLT-3']->id)->value('location_id'));

        Event::assertDispatched(InventoryEvent::class, fn ($e) => $e->eventType === 'inventory.count.completed'
            && $e->payload['missing'] === [$this->pallets['PLT-3']->system_id]);

        // Closed counts accept nothing more.
        $this->postJson("/api/v1/inventory/counts/{$id}/scan", ['serial_numbers' => ['PLT-3']], $headers)->assertStatus(409);
        $this->postJson("/api/v1/inventory/counts/{$id}/complete", [], $headers)->assertStatus(409);
    }

    public function test_count_without_reconcile_leaves_core_untouched(): void
    {
        $this->enableInventory($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->rackA->id], $headers)->json('data.id');
        $this->postJson("/api/v1/inventory/counts/{$id}/scan", ['serial_numbers' => ['PLT-4']], $headers)->assertOk();
        $this->postJson("/api/v1/inventory/counts/{$id}/complete", [], $headers)
            ->assertOk()->assertJsonPath('data.summary.reconciled', 0)->assertJsonPath('data.summary.missing', 4);

        $this->assertSame($this->dock->id, AssetLocation::where('asset_id', $this->pallets['PLT-4']->id)->value('location_id'));
    }

    public function test_viewer_reads_but_cannot_adjust(): void
    {
        $this->enableInventory($this->project);
        $this->actingAsRole($this->project, 'viewer');
        $headers = $this->headers();

        $this->getJson('/api/v1/inventory/stock', $headers)->assertOk();
        $this->getJson('/api/v1/inventory/counts', $headers)->assertOk();
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $this->rackA->id, 'min_quantity' => 1], $headers)->assertStatus(403);
        $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->rackA->id], $headers)->assertStatus(403);
    }

    public function test_other_tenants_are_isolated(): void
    {
        $foreign = Project::factory()->create();
        $foreignLocation = Location::factory()->create($this->scope($foreign));
        $this->place(Asset::factory()->create($this->scope($foreign) + ['serial_number' => 'FOREIGN-1', 'asset_type' => 'pallet']), $foreignLocation);
        $this->enableInventory($foreign);

        $this->enableInventory($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers();

        // Stock only covers this project.
        $rows = $this->getJson('/api/v1/inventory/stock', $headers)->json('data');
        $this->assertNull(collect($rows)->firstWhere('location_id', $foreignLocation->id));

        // Foreign locations cannot be counted or given minimums.
        $this->postJson('/api/v1/inventory/counts', ['location_id' => $foreignLocation->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->putJson('/api/v1/inventory/levels', ['location_id' => $foreignLocation->id, 'min_quantity' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('location_id');

        // A scanned serial that exists only in the other project is unknown here.
        $id = $this->postJson('/api/v1/inventory/counts', ['location_id' => $this->dock->id], $headers)->json('data.id');
        $this->postJson("/api/v1/inventory/counts/{$id}/scan", ['serial_numbers' => ['FOREIGN-1', 'PLT-4']], $headers)
            ->assertOk()->assertJsonPath('data.scanned', 1)->assertJsonPath('data.unknown', ['FOREIGN-1']);

        // Another tenant's count is not reachable.
        $otherCount = \App\Modules\Inventory\Models\InventoryCount::create($this->scope($foreign) + ['location_id' => $foreignLocation->id]);
        $this->getJson("/api/v1/inventory/counts/{$otherCount->id}", $headers)->assertNotFound();
    }
}
