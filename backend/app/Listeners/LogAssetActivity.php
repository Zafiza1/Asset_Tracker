<?php

namespace App\Listeners;

use App\Events\AssetCreated;
use App\Events\AssetDeleted;
use App\Events\AssetLocationUpdated;
use App\Events\AssetStatusChanged;
use App\Events\AssetUpdated;
use App\Services\AuditService;

/**
 * Writes the activity log row for asset lifecycle events.
 *
 * Runs synchronously (not queued) so the acting user, IP and user agent of
 * the originating request are still available to AuditService.
 */
class LogAssetActivity
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(AssetCreated|AssetUpdated|AssetDeleted|AssetStatusChanged|AssetLocationUpdated $event): void
    {
        $action = match ($event::class) {
            AssetCreated::class => 'create',
            AssetUpdated::class => 'update',
            AssetDeleted::class => 'delete',
            AssetStatusChanged::class => 'status_change',
            AssetLocationUpdated::class => 'location_update',
        };

        $this->auditService->logActivity(
            action: $action,
            resourceType: 'Asset',
            resourceId: $event->payload['asset_id'] ?? null,
            metadata: $event->payload,
            organizationId: $event->organizationId,
            projectId: $event->projectId
        );
    }
}
