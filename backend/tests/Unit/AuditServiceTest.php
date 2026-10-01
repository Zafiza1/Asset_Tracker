<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\EventLog;
use App\Models\SecurityLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditService = app(AuditService::class);
    }

    public function test_log_activity_creates_activity_log(): void
    {
        $user = \App\Models\User::factory()->create();
        $organization = \App\Models\Organization::factory()->create();
        $project = \App\Models\Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user);

        $log = $this->auditService->logActivity(
            action: 'create',
            resourceType: 'Asset',
            resourceId: 123,
            metadata: ['test' => 'data'],
            organizationId: $organization->id,
            projectId: $project->id
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertEquals('create', $log->action);
        $this->assertEquals('Asset', $log->resource_type);
        $this->assertEquals(123, $log->resource_id);
        $this->assertEquals($organization->id, $log->organization_id);
        $this->assertEquals($project->id, $log->project_id);
        $this->assertEquals(['test' => 'data'], $log->metadata);
    }

    public function test_log_security_event_creates_security_log(): void
    {
        $user = \App\Models\User::factory()->create();
        $organization = \App\Models\Organization::factory()->create();

        $this->actingAs($user);

        $log = $this->auditService->logSecurityEvent(
            eventType: 'login_failed',
            severity: 'high',
            details: ['email' => 'test@example.com'],
            isSuspicious: true,
            organizationId: $organization->id
        );

        $this->assertInstanceOf(SecurityLog::class, $log);
        $this->assertEquals('login_failed', $log->event_type);
        $this->assertEquals('high', $log->severity);
        $this->assertTrue($log->is_suspicious);
        $this->assertEquals($organization->id, $log->organization_id);
    }

    public function test_log_event_creates_event_log(): void
    {
        $organization = \App\Models\Organization::factory()->create();
        $project = \App\Models\Project::factory()->create(['organization_id' => $organization->id]);
        $asset = \App\Models\Asset::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $log = $this->auditService->logEvent(
            eventType: 'asset.location.updated',
            source: 'gps',
            payload: ['lat' => 1.23, 'lng' => 4.56],
            organizationId: $organization->id,
            projectId: $project->id,
            assetId: $asset->id
        );

        $this->assertInstanceOf(EventLog::class, $log);
        $this->assertEquals('asset.location.updated', $log->event_type);
        $this->assertEquals('gps', $log->source);
        $this->assertEquals($organization->id, $log->organization_id);
        $this->assertEquals($project->id, $log->project_id);
        $this->assertEquals($asset->id, $log->asset_id);
        $this->assertEquals('pending', $log->status);
    }

    public function test_get_activity_logs_filters_by_organization(): void
    {
        $org1 = \App\Models\Organization::factory()->create();
        $org2 = \App\Models\Organization::factory()->create();

        \App\Models\ActivityLog::factory()->create(['organization_id' => $org1->id]);
        \App\Models\ActivityLog::factory()->create(['organization_id' => $org1->id]);
        \App\Models\ActivityLog::factory()->create(['organization_id' => $org2->id]);

        $logs = $this->auditService->getActivityLogs(organizationId: $org1->id);

        $this->assertCount(2, $logs->items());
    }

    public function test_get_security_logs_filters_by_severity(): void
    {
        \App\Models\SecurityLog::factory()->create(['severity' => 'low']);
        \App\Models\SecurityLog::factory()->create(['severity' => 'high']);
        \App\Models\SecurityLog::factory()->create(['severity' => 'high']);

        $logs = $this->auditService->getSecurityLogs(severity: 'high');

        $this->assertCount(2, $logs->items());
    }

    public function test_get_event_logs_filters_by_status(): void
    {
        \App\Models\EventLog::factory()->create(['status' => 'pending']);
        \App\Models\EventLog::factory()->create(['status' => 'processed']);
        \App\Models\EventLog::factory()->create(['status' => 'processed']);

        $logs = $this->auditService->getEventLogs(status: 'processed');

        $this->assertCount(2, $logs->items());
    }
}
