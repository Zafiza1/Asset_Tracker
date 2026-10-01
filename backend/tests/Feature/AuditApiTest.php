<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SecurityLog;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Audit access is permission-based. Seed the canonical role/permission
        // catalogue explicitly; RefreshDatabase intentionally starts empty.
        $this->seed(RoleAndPermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);

        $this->user->organizations()->attach($this->organization->id);
        $this->user->projects()->attach($this->project->id);
        $this->user->assignRole('organization-owner', $this->organization->id);
    }

    public function test_can_get_activity_logs_with_permission(): void
    {
        ActivityLog::factory()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Organization-Id' => $this->organization->id, 'X-Project-Id' => $this->project->id])
            ->getJson('/api/v1/audit/activity-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'action',
                        'resource_type',
                        'resource_id',
                        'occurred_at',
                    ],
                ],
            ]);
    }

    public function test_can_get_security_logs_with_permission(): void
    {
        SecurityLog::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Organization-Id' => $this->organization->id])
            ->getJson('/api/v1/audit/security-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'event_type',
                        'severity',
                        'is_suspicious',
                        'occurred_at',
                    ],
                ],
            ]);
    }

    public function test_can_get_audit_stats(): void
    {
        ActivityLog::factory()->count(5)->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
        ]);

        SecurityLog::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Organization-Id' => $this->organization->id, 'X-Project-Id' => $this->project->id])
            ->getJson('/api/v1/audit/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'activity_logs' => [
                    'total',
                    'by_action',
                ],
                'security_logs' => [
                    'total',
                    'suspicious',
                    'by_severity',
                ],
                'event_logs' => [
                    'total',
                    'pending',
                    'processed',
                    'failed',
                ],
            ]);
    }

    public function test_cannot_access_audit_logs_without_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->organization->id);
        $viewer->projects()->attach($this->project->id);
        $viewer->assignRole('viewer', $this->organization->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Organization-Id' => $this->organization->id, 'X-Project-Id' => $this->project->id])
            ->getJson('/api/v1/audit/activity-logs');

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_access_audit_stats(): void
    {
        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->organization->id);
        $viewer->projects()->attach($this->project->id);
        $viewer->assignRole('viewer', $this->organization->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Organization-Id' => $this->organization->id, 'X-Project-Id' => $this->project->id])
            ->getJson('/api/v1/audit/stats');

        $response->assertStatus(403);
    }
}
