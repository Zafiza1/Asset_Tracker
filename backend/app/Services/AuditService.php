<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EventLog;
use App\Models\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function logActivity(
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $metadata = null,
        ?int $userId = null,
        ?int $organizationId = null,
        ?int $projectId = null
    ): ActivityLog {
        $user = $userId ? \App\Models\User::find($userId) : Auth::user();
        $request = request();

        return ActivityLog::create([
            'user_id' => $user?->id,
            'organization_id' => $organizationId ?? ($user?->current_organization_id),
            'project_id' => $projectId ?? ($user?->current_project_id),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function logSecurityEvent(
        string $eventType,
        string $severity = 'low',
        ?array $details = null,
        bool $isSuspicious = false,
        ?int $userId = null,
        ?int $organizationId = null
    ): SecurityLog {
        $user = $userId ? \App\Models\User::find($userId) : Auth::user();
        $request = request();

        return SecurityLog::create([
            'user_id' => $user?->id,
            'organization_id' => $organizationId ?? ($user?->current_organization_id),
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'details' => $details,
            'is_suspicious' => $isSuspicious,
            'occurred_at' => now(),
        ]);
    }

    public function logEvent(
        string $eventType,
        string $source,
        array $payload,
        ?int $organizationId = null,
        ?int $projectId = null,
        ?int $assetId = null,
        ?int $deviceId = null,
        ?int $integrationId = null,
        ?array $metadata = null
    ): EventLog {
        return EventLog::create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'asset_id' => $assetId,
            'device_id' => $deviceId,
            'integration_id' => $integrationId,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
            'occurred_at' => now(),
            'status' => 'pending',
            'metadata' => $metadata,
        ]);
    }

    public function logLoginSuccess(?int $userId = null): void
    {
        $user = $userId ? \App\Models\User::find($userId) : Auth::user();
        if (!$user) {
            return;
        }

        $this->logActivity('login', 'User', $user->id, [
            'email' => $user->email,
            'timestamp' => now()->toIso8601String(),
        ], $user->id);

        $this->logSecurityEvent('login_success', 'low', [
            'email' => $user->email,
        ], false, $user->id);
    }

    public function logLoginFailed(string $email, ?string $ip = null): void
    {
        $this->logSecurityEvent('login_failed', 'medium', [
            'email' => $email,
            'attempted_at' => now()->toIso8601String(),
        ], true, null, null);
    }

    public function logLogout(?int $userId = null): void
    {
        $user = $userId ? \App\Models\User::find($userId) : Auth::user();
        if (!$user) {
            return;
        }

        $this->logActivity('logout', 'User', $user->id, [
            'email' => $user->email,
        ], $user->id);

        $this->logSecurityEvent('logout', 'low', [
            'email' => $user->email,
        ], false, $user->id);
    }

    public function logUnauthorizedAccess(?string $ip = null): void
    {
        $this->logSecurityEvent('unauthorized_access', 'high', [
            'attempted_at' => now()->toIso8601String(),
        ], true, null, null);
    }

    public function logRateLimitExceeded(?int $userId = null): void
    {
        $user = $userId ? \App\Models\User::find($userId) : Auth::user();

        $this->logSecurityEvent('rate_limit_exceeded', 'medium', [
            'user_email' => $user?->email,
        ], true, $user?->id, $user?->current_organization_id);
    }

    public function getActivityLogs(
        ?int $organizationId = null,
        ?int $projectId = null,
        ?int $userId = null,
        ?string $action = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?int $days = 30,
        int $perPage = 50
    ) {
        $query = ActivityLog::query();

        if ($organizationId) {
            $query->byOrganization($organizationId);
        }

        if ($projectId) {
            $query->byProject($projectId);
        }

        if ($userId) {
            $query->byUser($userId);
        }

        if ($action) {
            $query->byAction($action);
        }

        if ($resourceType) {
            $query->byResource($resourceType, $resourceId);
        }

        if ($days) {
            $query->recent($days);
        }

        return $query->latest('occurred_at')->paginate($perPage);
    }

    public function getSecurityLogs(
        ?int $organizationId = null,
        ?int $userId = null,
        ?string $eventType = null,
        ?string $severity = null,
        ?bool $suspiciousOnly = false,
        ?int $days = 30,
        int $perPage = 50
    ) {
        $query = SecurityLog::query();

        if ($organizationId) {
            $query->byOrganization($organizationId);
        }

        if ($userId) {
            $query->byUser($userId);
        }

        if ($eventType) {
            $query->byEventType($eventType);
        }

        if ($severity) {
            $query->bySeverity($severity);
        }

        if ($suspiciousOnly) {
            $query->suspicious();
        }

        if ($days) {
            $query->recent($days);
        }

        return $query->latest('occurred_at')->paginate($perPage);
    }

    public function getEventLogs(
        ?int $organizationId = null,
        ?int $projectId = null,
        ?int $assetId = null,
        ?string $eventType = null,
        ?string $source = null,
        ?string $status = null,
        ?int $days = 30,
        int $perPage = 50
    ) {
        $query = EventLog::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($assetId) {
            $query->where('asset_id', $assetId);
        }

        if ($eventType) {
            $query->ofType($eventType);
        }

        if ($source) {
            $query->ofSource($source);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($days) {
            $query->where('occurred_at', '>=', now()->subDays($days));
        }

        return $query->latest('occurred_at')->paginate($perPage);
    }
}
