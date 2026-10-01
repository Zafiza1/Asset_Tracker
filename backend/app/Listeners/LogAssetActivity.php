<?php

namespace App\Listeners;

use App\Events\AssetCreated;
use App\Events\AssetDeleted;
use App\Events\AssetUpdated;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogAssetActivity implements ShouldQueue
{
    use InteractsWithQueue;

    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function handle(AssetCreated|AssetUpdated|AssetDeleted $event): void
    {
        $action = match ($event::class) {
            AssetCreated::class => 'create',
            AssetUpdated::class => 'update',
            AssetDeleted::class => 'delete',
            default => 'unknown',
        };

        $this->auditService->logActivity(
            action: $action,
            resourceType: 'Asset',
            resourceId: $event->asset->id,
            metadata: [
                'system_id' => $event->asset->system_id,
                'serial_number' => $event->asset->serial_number,
                'name' => $event->asset->name,
                'organization_id' => $event->asset->organization_id,
                'project_id' => $event->asset->project_id,
            ],
            organizationId: $event->asset->organization_id,
            projectId: $event->asset->project_id
        );
    }
}
