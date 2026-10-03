<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Events\CustomerChanged;
use App\Modules\Customer\Models\Customer;
use App\Services\ModuleRegistry;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
        app(ModuleRegistry::class)->syncFromConfig();

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id]);
    }

    protected function enableCustomer(Project $project): void
    {
        $modules = app(ModuleService::class);
        $modules->enable($modules->install($project, 'customer'));
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

    protected function makeCustomer(Project $project, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => 'CUST-' . uniqid(),
            'name' => 'Customer',
        ], $attributes));
    }

    public function test_endpoints_are_unavailable_until_the_module_is_enabled(): void
    {
        $this->actingAsRole($this->project, 'project-admin');
        $this->getJson('/api/v1/customers', $this->headers($this->project))->assertStatus(403);

        $this->enableCustomer($this->project);
        $this->getJson('/api/v1/customers', $this->headers($this->project))->assertOk();
    }

    public function test_manager_creates_updates_and_lists_customers_with_a_site(): void
    {
        Event::fake([CustomerChanged::class]);
        $this->enableCustomer($this->project);
        $this->actingAsRole($this->project, 'manager');
        $headers = $this->headers($this->project);
        $site = Location::factory()->create([
            'organization_id' => $this->project->organization_id,
            'project_id' => $this->project->id,
            'name' => 'Site A',
            'type' => 'customer_site',
        ]);

        $id = $this->postJson('/api/v1/customers', [
            'code' => 'CUST-001',
            'name' => 'Rumah Sakit Sehat',
            'email' => 'ops@example.com',
            'location_id' => $site->id,
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.location.name', 'Site A')
            ->json('data.id');
        Event::assertDispatched(CustomerChanged::class, fn ($e) => $e->eventType === 'customer.created' && $e->payload['code'] === 'CUST-001');

        $this->putJson("/api/v1/customers/{$id}", ['status' => 'inactive', 'phone' => '021-555'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.phone', '021-555');
        Event::assertDispatched(CustomerChanged::class, fn ($e) => $e->eventType === 'customer.updated');

        $this->getJson('/api/v1/customers?search=sehat&status=inactive', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'CUST-001');

        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.updated', 'resource_id' => $id]);
    }

    public function test_code_is_unique_within_a_project_and_reusable_after_delete(): void
    {
        $this->enableCustomer($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers($this->project);

        $id = $this->postJson('/api/v1/customers', ['code' => 'C-1', 'name' => 'First'], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers', ['code' => 'C-1', 'name' => 'Dup'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // Another project may use the same code.
        $other = Project::factory()->create(['organization_id' => $this->project->organization_id]);
        $this->makeCustomer($other, ['code' => 'C-1']);

        $this->deleteJson("/api/v1/customers/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $id]);
        $this->getJson("/api/v1/customers/{$id}", $headers)->assertNotFound();

        $this->postJson('/api/v1/customers', ['code' => 'C-1', 'name' => 'Second'], $headers)->assertCreated();
    }

    public function test_site_must_be_a_location_of_the_same_project(): void
    {
        $this->enableCustomer($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $other = Project::factory()->create(['organization_id' => $this->project->organization_id]);
        $foreignSite = Location::factory()->create(['organization_id' => $other->organization_id, 'project_id' => $other->id]);

        $this->postJson('/api/v1/customers', ['code' => 'C-2', 'name' => 'X', 'location_id' => $foreignSite->id], $this->headers($this->project))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_viewer_and_operator_are_read_only_and_manager_cannot_delete(): void
    {
        $this->enableCustomer($this->project);
        $customer = $this->makeCustomer($this->project);
        $headers = $this->headers($this->project);

        foreach (['viewer', 'operator'] as $role) {
            $this->actingAsRole($this->project, $role);
            $this->getJson('/api/v1/customers', $headers)->assertOk();
            $this->postJson('/api/v1/customers', ['code' => 'NEW', 'name' => 'X'], $headers)->assertStatus(403);
            $this->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Y'], $headers)->assertStatus(403);
        }

        $this->actingAsRole($this->project, 'manager');
        $this->deleteJson("/api/v1/customers/{$customer->id}", [], $headers)->assertStatus(403);
    }

    public function test_customers_of_another_tenant_are_not_reachable(): void
    {
        $foreignProject = Project::factory()->create();
        $this->enableCustomer($foreignProject);
        $foreign = $this->makeCustomer($foreignProject);

        $this->enableCustomer($this->project);
        $this->actingAsRole($this->project, 'project-admin');
        $headers = $this->headers($this->project);

        $this->getJson("/api/v1/customers/{$foreign->id}", $headers)->assertNotFound();
        $this->putJson("/api/v1/customers/{$foreign->id}", ['name' => 'Hijack'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/customers/{$foreign->id}", [], $headers)->assertNotFound();
        $this->getJson('/api/v1/customers', $headers)->assertJsonPath('meta.total', 0);

        // Claiming the other project's context without membership is refused.
        $this->getJson('/api/v1/customers', $this->headers($foreignProject))->assertStatus(403);
        $this->assertSame('Customer', $foreign->fresh()->name);
    }
}
