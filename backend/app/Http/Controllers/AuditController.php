<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\EventLogResource;
use App\Http\Resources\SecurityLogResource;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function indexActivityLogs(Request $request)
    {
        $this->authorize('viewAny', \App\Models\ActivityLog::class);

        $logs = $this->auditService->getActivityLogs(
            organizationId: $request->input('organization_id'),
            projectId: $request->input('project_id'),
            userId: $request->input('user_id'),
            action: $request->input('action'),
            resourceType: $request->input('resource_type'),
            resourceId: $request->input('resource_id'),
            days: $request->input('days', 30),
            perPage: $request->input('per_page', 50)
        );

        return ActivityLogResource::collection($logs);
    }

    public function showActivityLog($id)
    {
        $this->authorize('view', \App\Models\ActivityLog::class);

        $log = \App\Models\ActivityLog::with(['user', 'organization', 'project'])
            ->findOrFail($id);

        return new ActivityLogResource($log);
    }

    public function indexSecurityLogs(Request $request)
    {
        $this->authorize('viewAny', \App\Models\SecurityLog::class);

        $logs = $this->auditService->getSecurityLogs(
            organizationId: $request->input('organization_id'),
            userId: $request->input('user_id'),
            eventType: $request->input('event_type'),
            severity: $request->input('severity'),
            suspiciousOnly: $request->boolean('suspicious_only', false),
            days: $request->input('days', 30),
            perPage: $request->input('per_page', 50)
        );

        return SecurityLogResource::collection($logs);
    }

    public function showSecurityLog($id)
    {
        $this->authorize('view', \App\Models\SecurityLog::class);

        $log = \App\Models\SecurityLog::with(['user', 'organization'])
            ->findOrFail($id);

        return new SecurityLogResource($log);
    }

    public function indexEventLogs(Request $request)
    {
        $this->authorize('viewAny', \App\Models\EventLog::class);

        $logs = $this->auditService->getEventLogs(
            organizationId: $request->input('organization_id'),
            projectId: $request->input('project_id'),
            assetId: $request->input('asset_id'),
            eventType: $request->input('event_type'),
            source: $request->input('source'),
            status: $request->input('status'),
            days: $request->input('days', 30),
            perPage: $request->input('per_page', 50)
        );

        return EventLogResource::collection($logs);
    }

    public function showEventLog($id)
    {
        $this->authorize('view', \App\Models\EventLog::class);

        $log = \App\Models\EventLog::with(['organization', 'project', 'asset', 'device', 'integration'])
            ->findOrFail($id);

        return new EventLogResource($log);
    }

    public function getStats(Request $request)
    {
        $this->authorize('viewAny', \App\Models\ActivityLog::class);

        $days = $request->input('days', 30);
        $organizationId = $request->input('organization_id');
        $projectId = $request->input('project_id');

        $activityQuery = \App\Models\ActivityLog::query();
        $securityQuery = \App\Models\SecurityLog::query();
        $eventQuery = \App\Models\EventLog::query();

        if ($organizationId) {
            $activityQuery->where('organization_id', $organizationId);
            $securityQuery->where('organization_id', $organizationId);
            $eventQuery->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $activityQuery->where('project_id', $projectId);
            $eventQuery->where('project_id', $projectId);
        }

        $activityQuery->where('occurred_at', '>=', now()->subDays($days));
        $securityQuery->where('occurred_at', '>=', now()->subDays($days));
        $eventQuery->where('occurred_at', '>=', now()->subDays($days));

        return response()->json([
            'activity_logs' => [
                'total' => $activityQuery->count(),
                'by_action' => $activityQuery->selectRaw('action, COUNT(*) as count')
                    ->groupBy('action')
                    ->pluck('count', 'action')
                    ->toArray(),
            ],
            'security_logs' => [
                'total' => $securityQuery->count(),
                'suspicious' => $securityQuery->where('is_suspicious', true)->count(),
                'by_severity' => $securityQuery->selectRaw('severity, COUNT(*) as count')
                    ->groupBy('severity')
                    ->pluck('count', 'severity')
                    ->toArray(),
                'by_event_type' => $securityQuery->selectRaw('event_type, COUNT(*) as count')
                    ->groupBy('event_type')
                    ->pluck('count', 'event_type')
                    ->toArray(),
            ],
            'event_logs' => [
                'total' => $eventQuery->count(),
                'pending' => $eventQuery->where('status', 'pending')->count(),
                'processed' => $eventQuery->where('status', 'processed')->count(),
                'failed' => $eventQuery->where('status', 'failed')->count(),
                'by_event_type' => $eventQuery->selectRaw('event_type, COUNT(*) as count')
                    ->groupBy('event_type')
                    ->pluck('count', 'event_type')
                    ->toArray(),
                'by_source' => $eventQuery->selectRaw('source, COUNT(*) as count')
                    ->groupBy('source')
                    ->pluck('count', 'source')
                    ->toArray(),
            ],
        ]);
    }
}

