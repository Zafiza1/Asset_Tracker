<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\EventLogResource;
use App\Http\Resources\SecurityLogResource;
use App\Models\ActivityLog;
use App\Models\EventLog;
use App\Models\SecurityLog;
use App\Services\AuditService;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Audit logs are not tenant-scoped models, so every query here is pinned to
 * the organization/project TenantMiddleware validated. Request parameters
 * can only narrow that scope — except for platform admins, who may query
 * any tenant.
 */
class AuditController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function indexActivityLogs(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);
        [$organizationId, $projectId] = $this->scope($request);

        $logs = $this->auditService->getActivityLogs(
            organizationId: $organizationId,
            projectId: $projectId,
            userId: $request->integer('user_id') ?: null,
            action: $request->input('action'),
            resourceType: $request->input('resource_type'),
            resourceId: $request->integer('resource_id') ?: null,
            days: $request->integer('days', 30),
            perPage: $this->perPage($request)
        );

        return ActivityLogResource::collection($logs);
    }

    public function showActivityLog(Request $request, $id)
    {
        $log = $this->scoped(ActivityLog::query(), $request)
            ->with(['user', 'organization', 'project'])
            ->findOrFail($id);

        $this->authorize('view', $log);

        return new ActivityLogResource($log);
    }

    public function indexSecurityLogs(Request $request)
    {
        $this->authorize('viewAny', SecurityLog::class);
        [$organizationId] = $this->scope($request);

        $logs = $this->auditService->getSecurityLogs(
            organizationId: $organizationId,
            userId: $request->integer('user_id') ?: null,
            eventType: $request->input('event_type'),
            severity: $request->input('severity'),
            suspiciousOnly: $request->boolean('suspicious_only', false),
            days: $request->integer('days', 30),
            perPage: $this->perPage($request)
        );

        return SecurityLogResource::collection($logs);
    }

    public function showSecurityLog(Request $request, $id)
    {
        $log = $this->scoped(SecurityLog::query(), $request, withProject: false)
            ->with(['user', 'organization'])
            ->findOrFail($id);

        $this->authorize('view', $log);

        return new SecurityLogResource($log);
    }

    public function indexEventLogs(Request $request)
    {
        $this->authorize('viewAny', EventLog::class);
        [$organizationId, $projectId] = $this->scope($request);

        $logs = $this->auditService->getEventLogs(
            organizationId: $organizationId,
            projectId: $projectId,
            assetId: $request->integer('asset_id') ?: null,
            eventType: $request->input('event_type'),
            source: $request->input('source'),
            status: $request->input('status'),
            days: $request->integer('days', 30),
            perPage: $this->perPage($request)
        );

        return EventLogResource::collection($logs);
    }

    public function showEventLog(Request $request, $id)
    {
        $log = $this->scoped(EventLog::query(), $request)
            ->with(['organization', 'project', 'asset', 'device', 'integration'])
            ->findOrFail($id);

        $this->authorize('view', $log);

        return new EventLogResource($log);
    }

    public function getStats(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);

        $since = now()->subDays($request->integer('days', 30));
        $activity = fn () => $this->scoped(ActivityLog::query(), $request)->where('occurred_at', '>=', $since);
        $security = fn () => $this->scoped(SecurityLog::query(), $request, withProject: false)->where('occurred_at', '>=', $since);
        $events = fn () => $this->scoped(EventLog::query(), $request)->where('occurred_at', '>=', $since);

        // Each figure gets a fresh query: reusing one builder would stack
        // the previous where/groupBy clauses onto the next count.
        $grouped = fn (Builder $query, string $column) => $query
            ->selectRaw("{$column}, COUNT(*) as count")
            ->groupBy($column)
            ->pluck('count', $column)
            ->toArray();

        return response()->json([
            'activity_logs' => [
                'total' => $activity()->count(),
                'by_action' => $grouped($activity(), 'action'),
            ],
            'security_logs' => [
                'total' => $security()->count(),
                'suspicious' => $security()->where('is_suspicious', true)->count(),
                'by_severity' => $grouped($security(), 'severity'),
                'by_event_type' => $grouped($security(), 'event_type'),
            ],
            'event_logs' => [
                'total' => $events()->count(),
                'pending' => $events()->where('status', 'pending')->count(),
                'processed' => $events()->where('status', 'processed')->count(),
                'failed' => $events()->where('status', 'failed')->count(),
                'by_event_type' => $grouped($events(), 'event_type'),
                'by_source' => $grouped($events(), 'source'),
            ],
        ]);
    }

    /**
     * The organization/project a query must be limited to.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function scope(Request $request): array
    {
        if ($request->user()?->isPlatformAdmin()) {
            return [
                $request->integer('organization_id') ?: TenantScope::getCurrentOrganizationId(),
                $request->integer('project_id') ?: TenantScope::getCurrentProjectId(),
            ];
        }

        $organizationId = TenantScope::getCurrentOrganizationId();

        abort_if(!$organizationId, 422, 'An organization context (X-Organization-Id header) is required');

        return [$organizationId, TenantScope::getCurrentProjectId()];
    }

    protected function scoped(Builder $query, Request $request, bool $withProject = true): Builder
    {
        [$organizationId, $projectId] = $this->scope($request);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($withProject && $projectId) {
            $query->where('project_id', $projectId);
        }

        return $query;
    }

    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 50), 1), 100);
    }
}
