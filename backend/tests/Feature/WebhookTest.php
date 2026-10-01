<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
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

    public function test_user_can_create_webhook(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $response = $this->postJson('/api/v1/webhooks', [
            'name' => 'Test Webhook',
            'endpoint' => 'https://example.com/webhook',
            'events' => ['asset.created', 'asset.updated'],
            'active' => true,
        ], $this->tenantHeaders($project));

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Test Webhook',
                    'endpoint' => 'https://example.com/webhook',
                    'events' => ['asset.created', 'asset.updated'],
                    'active' => true,
                ],
            ]);

        $this->assertDatabaseHas('webhooks', [
            'name' => 'Test Webhook',
            'endpoint' => 'https://example.com/webhook',
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
    }

    public function test_user_cannot_create_webhook_without_permission(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'viewer');

        $response = $this->postJson('/api/v1/webhooks', [
            'name' => 'Test Webhook',
            'endpoint' => 'https://example.com/webhook',
            'events' => ['asset.created'],
        ], $this->tenantHeaders($project));

        $response->assertStatus(403);
    }

    public function test_user_can_list_webhooks(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->getJson('/api/v1/webhooks', $this->tenantHeaders($project));

        $response->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_can_update_webhook(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => 'Old Name',
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->putJson("/api/v1/webhooks/{$webhook->id}", [
            'name' => 'New Name',
        ], $this->tenantHeaders($project));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'New Name',
                ],
            ]);

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'name' => 'New Name',
        ]);
    }

    public function test_user_can_delete_webhook(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->deleteJson("/api/v1/webhooks/{$webhook->id}", [], $this->tenantHeaders($project));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('webhooks', [
            'id' => $webhook->id,
        ]);
    }

    public function test_webhook_secret_is_auto_generated(): void
    {
        $project = Project::factory()->create();
        $this->actingAsProjectUser($project, 'manager');

        $response = $this->postJson('/api/v1/webhooks', [
            'name' => 'Test Webhook',
            'endpoint' => 'https://example.com/webhook',
            'events' => ['asset.created'],
        ], $this->tenantHeaders($project));

        $response->assertStatus(201);

        $webhook = Webhook::first();
        $this->assertNotNull($webhook->secret);
        $this->assertStringStartsWith('wh_', $webhook->secret);
    }

    public function test_user_can_regenerate_webhook_secret(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'secret' => 'wh_old_secret_12345',
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->postJson("/api/v1/webhooks/{$webhook->id}/regenerate-secret", [], $this->tenantHeaders($project));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $webhook->refresh();
        $this->assertNotEquals('wh_old_secret_12345', $webhook->secret);
    }

    public function test_user_can_toggle_webhook_active_status(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'active' => true,
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->postJson("/api/v1/webhooks/{$webhook->id}/toggle-active", [], $this->tenantHeaders($project));

        $response->assertStatus(200);

        $webhook->refresh();
        $this->assertFalse($webhook->active);
    }

    public function test_user_can_view_webhook_deliveries(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        WebhookDelivery::factory()->create([
            'webhook_id' => $webhook->id,
            'event_type' => 'asset.created',
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->getJson("/api/v1/webhooks/{$webhook->id}/deliveries", $this->tenantHeaders($project));

        $response->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_can_view_webhook_stats(): void
    {
        $project = Project::factory()->create();

        $webhook = Webhook::factory()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);

        WebhookDelivery::factory()->create([
            'webhook_id' => $webhook->id,
            'status' => 'delivered',
        ]);

        WebhookDelivery::factory()->create([
            'webhook_id' => $webhook->id,
            'status' => 'delivered',
        ]);

        $this->actingAsProjectUser($project, 'manager');

        $response = $this->getJson("/api/v1/webhooks/{$webhook->id}/stats", $this->tenantHeaders($project));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 2,
                    'delivered' => 2,
                    'failed' => 0,
                ],
            ]);
    }

    public function test_webhook_scoping_respects_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $project1 = Project::factory()->create(['organization_id' => $org1->id]);
        $project2 = Project::factory()->create(['organization_id' => $org2->id]);

        $webhook = Webhook::factory()->create([
            'organization_id' => $org1->id,
            'project_id' => $project1->id,
        ]);

        $this->actingAsProjectUser($project2, 'manager');

        $response = $this->getJson('/api/v1/webhooks', $this->tenantHeaders($project2));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_webhook_scoping_respects_project(): void
    {
        $organization = Organization::factory()->create();
        $project1 = Project::factory()->create(['organization_id' => $organization->id]);
        $project2 = Project::factory()->create(['organization_id' => $organization->id]);

        $webhook = Webhook::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project1->id,
        ]);

        $this->actingAsProjectUser($project2, 'manager');

        $response = $this->getJson('/api/v1/webhooks', $this->tenantHeaders($project2));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
