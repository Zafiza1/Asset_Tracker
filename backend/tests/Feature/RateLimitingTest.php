<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Small limits so each limiter trips within a handful of requests.
        config()->set('platform.rate_limits', [
            'auth' => 3,
            'api' => 5,
            'api_write' => 3,
            'integration' => 5,
        ]);
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        // Make 4 requests to login endpoint (limit is 3 per minute in testing)
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // The 4th request should be rate limited
        $response->assertStatus(429);
    }

    public function test_register_endpoint_is_rate_limited(): void
    {
        // Make 4 requests to register endpoint (limit is 3 per minute in testing)
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/auth/register', [
                'name' => 'Test User',
                'email' => "test{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);
        }

        // The 4th request should be rate limited
        $response->assertStatus(429);
    }

    public function test_api_read_endpoints_are_rate_limited(): void
    {
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Create a user and authenticate
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Make 6 requests to a read endpoint (limit is 5 per minute in testing)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->withToken($token)->getJson('/api/auth/me');
        }

        // The 6th request should be rate limited
        $response->assertStatus(429);
    }

    public function test_api_write_endpoints_have_stricter_rate_limit(): void
    {
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Create organization and project
        $organization = \App\Models\Organization::factory()->create();
        $project = \App\Models\Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        // Create user with access
        $user = \App\Models\User::factory()->create();
        $user->organizations()->attach($organization->id);
        $user->projects()->attach($project->id);
        $user->assignRole('manager', $organization->id, $project->id);
        $token = $user->createToken('test-token')->plainTextToken;

        // Make 4 requests to a write endpoint (limit is 3 per minute in testing)
        for ($i = 0; $i < 4; $i++) {
            $response = $this->withToken($token)
                ->withHeaders([
                    'X-Organization-Id' => $organization->id,
                    'X-Project-Id' => $project->id,
                ])
                ->postJson('/api/v1/locations', [
                    'name' => "Test Location {$i}",
                    'type' => 'warehouse',
                ]);
        }

        // The 4th request should be rate limited
        $response->assertStatus(429);
    }

    public function test_integration_endpoints_have_higher_rate_limit(): void
    {
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Create organization and project
        $organization = \App\Models\Organization::factory()->create();
        $project = \App\Models\Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        // Create integration
        $integration = \App\Models\Integration::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'type' => 'rfid',
        ]);

        // Create user with access
        $user = \App\Models\User::factory()->create();
        $user->organizations()->attach($organization->id);
        $user->projects()->attach($project->id);
        $user->assignRole('manager', $organization->id, $project->id);
        $token = $user->createToken('test-token')->plainTextToken;

        // Make 6 requests to integration endpoint (limit is 5 per minute in testing)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->withToken($token)
                ->withHeaders([
                    'X-Organization-Id' => $organization->id,
                    'X-Project-Id' => $project->id,
                ])
                ->postJson("/api/v1/integrations/{$integration->id}/rfid/ingest-read", [
                    'tag_id' => "TAG-{$i}",
                    'reader_id' => 'READER-001',
                    'timestamp' => now()->toIso8601String(),
                ]);
        }

        // The 6th request should be rate limited
        $response->assertStatus(429);
    }

    public function test_rate_limiting_by_ip_for_unauthenticated_requests(): void
    {
        // Make 4 requests from same IP (limit is 3 per minute for auth endpoints in testing)
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);
    }

    public function test_rate_limiting_by_user_id_for_authenticated_requests(): void
    {
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        // Create a user and authenticate
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Make 5 requests (hits limit of 5 in testing)
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        }

        // Should be rate limited
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(429);
    }
}
