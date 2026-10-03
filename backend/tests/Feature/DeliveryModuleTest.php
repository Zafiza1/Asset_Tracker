<?php

namespace Tests\Feature;

use App\Events\AssetLocationUpdated;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Delivery\Events\DeliveryChanged;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delivery moves assets to a customer's site and back, through Core's
 * MovementService only.
 */
class DeliveryModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Location $warehouse;

    protected Location $truck;

    protected Location $site;

    protected Customer $customer;

    /** @var array<int, Asset> */
    protected array $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
        $scope = ['organization_id' => $organization->id, 'project_id' => $this->project->id];

        $this->warehouse = Location::factory()->create($scope + ['name' => 'Warehouse', 'type' => 'warehouse']);
        $this->truck = Location::factory()->create($scope + ['name' => 'Truck 01', 'type' => 'vehicle']);
        $this->site = Location::factory()->create($scope + ['name' => 'Customer Site', 'type' => 'customer_site']);
        $this->customer = Customer::create($scope + ['code' => 'CUST-001', 'name' => 'Bengkel Las', 'location_id' => $this->site->id]);

        $movements = app(MovementService::class);
        foreach (['TAB-001', 'TAB-002'] as $serial) {
            $asset = Asset::factory()->create($scope + ['serial_number' => $serial]);
            $movements->recordMovement($asset, ['to_location_id' => $this->warehouse->id]);
            $this->assets[] = $asset;
        }
    }

    protected function enableDelivery(Project $project, array $configuration = []): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'customer'));
        $modules->enable($modules->install($project, 'delivery', null, $configuration));
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

    protected function headers(): array
    {
        return ['X-Organization-Id' => $this->project->organization_id, 'X-Project-Id' => $this->project->id];
    }

    protected function createDelivery(array $overrides = []): int
    {
        return $this->postJson('/api/v1/deliveries', array_merge([
            'customer_id' => $this->customer->id,
            'asset_ids' => array_map(fn (Asset $a) => $a->system_id, $this->assets),
            'reference' => 'DO-2026-0001',
        ], $overrides), $this->headers())->assertCreated()->json('data.id');
    }

    protected function locationOf(Asset $asset): ?int
    {
        return AssetLocation::where('asset_id', $asset->id)->value('location_id');
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');
        $this->getJson('/api/v1/deliveries', $this->headers())->assertStatus(403);

        $this->enableDelivery($this->project);
        $this->getJson('/api/v1/deliveries', $this->headers())->assertOk();
    }

    public function test_full_delivery_and_return_flow_moves_assets_through_core(): void
    {
        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'manager');
        Event::fake([DeliveryChanged::class, AssetLocationUpdated::class]);
        [$first, $second] = $this->assets;

        $id = $this->createDelivery();
        $this->getJson("/api/v1/deliveries/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.destination.name', 'Customer Site')
            ->assertJsonPath('data.customer.code', 'CUST-001')
            ->assertJsonCount(2, 'data.items');

        // Dispatch via the truck: assets ride along.
        $this->postJson("/api/v1/deliveries/{$id}/dispatch", ['via_location_id' => $this->truck->id], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame($this->truck->id, $this->locationOf($first));

        // Delivered: at the customer's site, recorded as delivery movements.
        $this->postJson("/api/v1/deliveries/{$id}/deliver", ['received_by' => 'Pak Budi'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.received_by', 'Pak Budi')
            ->assertJsonPath('data.items.0.status', 'delivered');
        $this->assertSame($this->site->id, $this->locationOf($first));
        $this->assertSame($this->site->id, $this->locationOf($second));
        $movement = Movement::where('asset_id', $first->id)->latest('id')->first();
        $this->assertSame('delivery', $movement->source);
        $this->assertSame($this->truck->id, $movement->from_location_id);
        $this->assertSame($id, $movement->metadata['delivery_id']);
        Event::assertDispatched(AssetLocationUpdated::class);

        // Partial return: one cylinder comes back, the delivery stays open.
        $this->postJson("/api/v1/deliveries/{$id}/return", ['to_location_id' => $this->warehouse->id, 'asset_ids' => [$first->system_id]], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame($this->warehouse->id, $this->locationOf($first));
        $this->assertSame($this->site->id, $this->locationOf($second));

        // Returning it twice is refused.
        $this->postJson("/api/v1/deliveries/{$id}/return", ['to_location_id' => $this->warehouse->id, 'asset_ids' => [$first->system_id]], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');

        // The rest comes back: delivery complete.
        $this->postJson("/api/v1/deliveries/{$id}/return", ['to_location_id' => $this->warehouse->id], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertSame($this->warehouse->id, $this->locationOf($second));

        foreach (['created', 'dispatched', 'delivered', 'returned'] as $action) {
            Event::assertDispatched(DeliveryChanged::class, fn ($e) => $e->eventType === "delivery.{$action}");
        }
        $this->assertDatabaseHas('activity_logs', ['action' => 'delivery.delivered', 'resource_id' => $id]);
    }

    public function test_an_asset_cannot_be_in_two_open_deliveries(): void
    {
        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'manager');

        $id = $this->createDelivery();
        $this->postJson('/api/v1/deliveries', [
            'customer_id' => $this->customer->id,
            'asset_ids' => [$this->assets[0]->system_id],
        ], $this->headers())->assertStatus(409);

        // Cancelling frees the assets again.
        $this->postJson("/api/v1/deliveries/{$id}/cancel", ['reason' => 'Customer postponed'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->createDelivery(['reference' => 'DO-2026-0002']);
    }

    public function test_state_rules_are_enforced(): void
    {
        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'manager');
        $id = $this->createDelivery();

        $this->postJson("/api/v1/deliveries/{$id}/return", ['to_location_id' => $this->warehouse->id], $this->headers())->assertStatus(409);
        $this->postJson("/api/v1/deliveries/{$id}/deliver", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/deliveries/{$id}/cancel", [], $this->headers())->assertStatus(409);
        $this->postJson("/api/v1/deliveries/{$id}/dispatch", [], $this->headers())->assertStatus(409);
        $this->putJson("/api/v1/deliveries/{$id}", ['notes' => 'late edit'], $this->headers())->assertStatus(409);
    }

    public function test_proof_of_delivery_and_destination_are_required_when_applicable(): void
    {
        $this->enableDelivery($this->project, ['require_proof_of_delivery' => true]);
        $this->actingAsRole($this->project, 'manager');

        $id = $this->createDelivery();
        $this->postJson("/api/v1/deliveries/{$id}/deliver", [], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('received_by');

        // A customer without a site and no explicit destination cannot be delivered to.
        $noSite = Customer::create(['organization_id' => $this->project->organization_id, 'project_id' => $this->project->id, 'code' => 'CUST-002', 'name' => 'No site']);
        $this->postJson("/api/v1/deliveries/{$id}/cancel", [], $this->headers())->assertOk();
        $other = $this->createDelivery(['customer_id' => $noSite->id]);
        $this->postJson("/api/v1/deliveries/{$other}/deliver", ['received_by' => 'X'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('destination_location_id');
    }

    public function test_inactive_customers_and_unknown_assets_are_rejected(): void
    {
        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'manager');

        $this->customer->update(['status' => 'inactive']);
        $this->postJson('/api/v1/deliveries', ['customer_id' => $this->customer->id, 'asset_ids' => [$this->assets[0]->system_id]], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->customer->update(['status' => 'active']);
        $this->postJson('/api/v1/deliveries', ['customer_id' => $this->customer->id, 'asset_ids' => ['AST-DOESNOTEXIST']], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_operator_moves_deliveries_but_viewer_is_read_only(): void
    {
        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'manager');
        $id = $this->createDelivery();

        $this->actingAsRole($this->project, 'operator');
        $this->postJson('/api/v1/deliveries', ['customer_id' => $this->customer->id, 'asset_ids' => [$this->assets[0]->system_id]], $this->headers())
            ->assertStatus(403);
        $this->postJson("/api/v1/deliveries/{$id}/deliver", [], $this->headers())->assertOk();

        $this->actingAsRole($this->project, 'viewer');
        $this->getJson('/api/v1/deliveries', $this->headers())->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/deliveries/{$id}/return", ['to_location_id' => $this->warehouse->id], $this->headers())->assertStatus(403);
    }

    public function test_other_tenants_cannot_be_reached_or_referenced(): void
    {
        $foreignProject = Project::factory()->create();
        $foreignScope = ['organization_id' => $foreignProject->organization_id, 'project_id' => $foreignProject->id];
        $foreignCustomer = Customer::create($foreignScope + ['code' => 'F-1', 'name' => 'Foreign']);
        $foreignAsset = Asset::factory()->create($foreignScope);
        $foreignLocation = Location::factory()->create($foreignScope);

        $this->enableDelivery($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers();

        $this->postJson('/api/v1/deliveries', ['customer_id' => $foreignCustomer->id, 'asset_ids' => [$this->assets[0]->system_id]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/deliveries', ['customer_id' => $this->customer->id, 'asset_ids' => [$foreignAsset->system_id]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
        $this->postJson('/api/v1/deliveries', ['customer_id' => $this->customer->id, 'asset_ids' => [$this->assets[0]->system_id], 'destination_location_id' => $foreignLocation->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('destination_location_id');

        $id = $this->createDelivery();
        $this->postJson("/api/v1/deliveries/{$id}/dispatch", ['via_location_id' => $foreignLocation->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('via_location_id');
    }
}
