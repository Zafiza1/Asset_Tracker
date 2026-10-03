<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Rental\Events\RentalChanged;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RentalModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Location $depot;

    protected Location $site;

    protected Customer $customer;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
        $scope = ['organization_id' => $organization->id, 'project_id' => $this->project->id];

        $this->depot = Location::factory()->create($scope + ['name' => 'Rental Depot']);
        $this->site = Location::factory()->create($scope + ['name' => 'Construction Site']);
        $this->customer = Customer::create($scope + ['code' => 'CUST-9', 'name' => 'PT Bangun', 'location_id' => $this->site->id]);
        $this->asset = Asset::factory()->create($scope + ['serial_number' => 'GEN-001', 'asset_type' => 'generator']);
        app(MovementService::class)->recordMovement($this->asset, ['to_location_id' => $this->depot->id]);
    }

    protected function enableRental(Project $project, array $configuration = []): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'customer'));
        $modules->enable($modules->install($project, 'rental', null, $configuration));
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

    protected function reserve(array $overrides = []): int
    {
        return $this->postJson('/api/v1/rentals', array_merge([
            'customer_id' => $this->customer->id,
            'asset_id' => $this->asset->system_id,
            'reference' => 'RENT-001',
        ], $overrides), $this->headers())->assertCreated()->json('data.id');
    }

    protected function locationOfAsset(): ?int
    {
        return AssetLocation::where('asset_id', $this->asset->id)->value('location_id');
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');
        $this->getJson('/api/v1/rentals', $this->headers())->assertStatus(403);

        $this->enableRental($this->project);
        $this->getJson('/api/v1/rentals', $this->headers())->assertOk();
    }

    public function test_rental_lifecycle_moves_the_asset_and_computes_amounts(): void
    {
        $this->enableRental($this->project, ['default_rental_period_days' => 3]);
        $this->actingAsRole($this->project, 'manager');
        Event::fake([RentalChanged::class]);
        $this->travelTo(now()->startOfHour());

        $id = $this->reserve(['daily_rate' => 150000]);
        $rental = $this->getJson("/api/v1/rentals/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.destination.name', 'Construction Site')
            ->json('data');
        // Due after the project's default period.
        $this->assertSame(now()->addDays(3)->timestamp, strtotime($rental['due_at']));

        $this->postJson("/api/v1/rentals/{$id}/checkout", [], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame($this->site->id, $this->locationOfAsset());
        $this->assertSame('rental', Movement::where('asset_id', $this->asset->id)->latest('id')->value('source'));

        // Back after 2 days and 1 hour: 3 rented days, on time.
        $this->travel(49)->hours();
        $this->postJson("/api/v1/rentals/{$id}/return", ['to_location_id' => $this->depot->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.rented_days', 3)
            ->assertJsonPath('data.days_late', 0)
            ->assertJsonPath('data.rental_amount', '450000.00')
            // Late fees are off for this project.
            ->assertJsonPath('data.late_fee', null);
        $this->assertSame($this->depot->id, $this->locationOfAsset());

        foreach (['created', 'checked_out', 'returned'] as $action) {
            Event::assertDispatched(RentalChanged::class, fn ($e) => $e->eventType === "rental.{$action}");
        }
        $this->assertDatabaseHas('activity_logs', ['action' => 'rental.returned', 'resource_id' => $id]);
    }

    public function test_late_returns_are_charged_when_late_fees_are_enabled(): void
    {
        $this->enableRental($this->project, ['late_fee_enabled' => true]);
        $this->actingAsRole($this->project, 'manager');
        $this->travelTo(now()->startOfHour());

        $id = $this->postJson('/api/v1/rentals', [
            'customer_id' => $this->customer->id,
            'asset_id' => $this->asset->system_id,
            'due_at' => now()->addDays(2)->toIso8601String(),
            'daily_rate' => 100,
            'late_fee_per_day' => 40,
            'checkout' => true,
        ], $this->headers())->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        // Two and a half days past due: 3 days late.
        $this->travel(4 * 24 + 12)->hours();
        $this->getJson('/api/v1/rentals?overdue=1', $this->headers())->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.overdue', true);

        $this->postJson("/api/v1/rentals/{$id}/return", ['to_location_id' => $this->depot->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.rented_days', 5)
            ->assertJsonPath('data.days_late', 3)
            ->assertJsonPath('data.rental_amount', '500.00')
            ->assertJsonPath('data.late_fee', '120.00');
    }

    public function test_extending_cancelling_and_state_rules(): void
    {
        $this->enableRental($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        $id = $this->reserve();
        $this->postJson("/api/v1/rentals/{$id}/return", ['to_location_id' => $this->depot->id], $headers)->assertStatus(409);

        $newDue = now()->addDays(30)->startOfSecond();
        $this->postJson("/api/v1/rentals/{$id}/extend", ['due_at' => $newDue->toIso8601String()], $headers)->assertOk();
        $this->assertSame($newDue->timestamp, strtotime($this->getJson("/api/v1/rentals/{$id}", $headers)->json('data.due_at')));
        $this->postJson("/api/v1/rentals/{$id}/extend", ['due_at' => now()->subDay()->toIso8601String()], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('due_at');

        // The asset is taken while reserved.
        $this->postJson('/api/v1/rentals', ['customer_id' => $this->customer->id, 'asset_id' => $this->asset->system_id], $headers)->assertStatus(409);

        $this->postJson("/api/v1/rentals/{$id}/cancel", ['reason' => 'Project delayed'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/rentals/{$id}/checkout", [], $headers)->assertStatus(409);

        // Free again; the asset never moved.
        $this->reserve(['reference' => 'RENT-002']);
        $this->assertSame($this->depot->id, $this->locationOfAsset());
    }

    public function test_validation_rules(): void
    {
        $this->enableRental($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers();

        $this->postJson('/api/v1/rentals', ['customer_id' => $this->customer->id, 'asset_id' => $this->asset->system_id, 'starts_at' => now()->addDay()->toIso8601String(), 'due_at' => now()->toIso8601String()], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('due_at');

        $this->customer->update(['status' => 'inactive']);
        $this->postJson('/api/v1/rentals', ['customer_id' => $this->customer->id, 'asset_id' => $this->asset->system_id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        // A customer without a site cannot be handed an asset without a destination.
        $noSite = Customer::create(['organization_id' => $this->project->organization_id, 'project_id' => $this->project->id, 'code' => 'CUST-10', 'name' => 'Walk-in']);
        $id = $this->reserve(['customer_id' => $noSite->id]);
        $this->postJson("/api/v1/rentals/{$id}/checkout", [], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('destination_location_id');
    }

    public function test_permissions(): void
    {
        $this->enableRental($this->project);
        $headers = $this->headers();

        // Operators hand over and take back, but do not create rentals.
        $this->actingAsRole($this->project, 'manager');
        $id = $this->reserve();
        $this->actingAsRole($this->project, 'operator');
        $this->postJson('/api/v1/rentals', ['customer_id' => $this->customer->id, 'asset_id' => $this->asset->system_id], $headers)->assertStatus(403);
        $this->postJson("/api/v1/rentals/{$id}/checkout", [], $headers)->assertOk();

        $this->actingAsRole($this->project, 'viewer');
        $this->getJson('/api/v1/rentals', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/rentals/{$id}/return", ['to_location_id' => $this->depot->id], $headers)->assertStatus(403);
    }

    public function test_other_tenants_are_isolated(): void
    {
        $foreign = Project::factory()->create();
        $scope = ['organization_id' => $foreign->organization_id, 'project_id' => $foreign->id];
        $foreignCustomer = Customer::create($scope + ['code' => 'F-1', 'name' => 'Foreign']);
        $foreignAsset = Asset::factory()->create($scope);
        $foreignLocation = Location::factory()->create($scope);
        $foreignRental = \App\Modules\Rental\Models\Rental::create($scope + [
            'customer_id' => $foreignCustomer->id, 'asset_id' => $foreignAsset->id, 'starts_at' => now(), 'due_at' => now()->addDay(),
        ]);

        $this->enableRental($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers();

        $this->getJson("/api/v1/rentals/{$foreignRental->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/rentals/{$foreignRental->id}/cancel", [], $headers)->assertNotFound();
        $this->postJson('/api/v1/rentals', ['customer_id' => $foreignCustomer->id, 'asset_id' => $this->asset->system_id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/rentals', ['customer_id' => $this->customer->id, 'asset_id' => $foreignAsset->system_id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('asset_id');

        $id = $this->reserve(['checkout' => true]);
        $this->postJson("/api/v1/rentals/{$id}/return", ['to_location_id' => $foreignLocation->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('to_location_id');
    }
}
