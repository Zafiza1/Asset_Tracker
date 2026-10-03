<?php

namespace App\Modules\Inventory\Listeners;

use App\Events\AssetLocationUpdated;
use App\Models\Asset;
use App\Models\Movement;
use App\Models\Project;
use App\Modules\Inventory\InventoryService;

/**
 * Reacts to Core's asset.location.updated: when an asset leaves a location,
 * check whether that location just dropped below its minimum stock.
 *
 * May run without a request tenant context (e.g. GPS ingestion through an API
 * key), so every lookup is scoped to the event's project explicitly.
 */
class CheckLowStock
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    public function handle(AssetLocationUpdated $event): void
    {
        $project = Project::withoutGlobalScopes()->find($event->projectId);

        if (!$project || !$project->hasModuleEnabled(InventoryService::MODULE_SLUG)) {
            return;
        }

        $assetId = $event->payload['asset_id'] ?? null;

        // The event is dispatched after commit, so the movement that caused it
        // is the asset's latest.
        $fromLocationId = Movement::withoutGlobalScopes()
            ->where('asset_id', $assetId)
            ->latest('id')
            ->value('from_location_id');

        if (!$fromLocationId || $fromLocationId === ($event->payload['location_id'] ?? null)) {
            return;
        }

        $asset = Asset::withoutGlobalScopes()->where('project_id', $project->id)->find($assetId);

        if ($asset) {
            $this->inventory->checkLowStockAfterDeparture($project, $asset, $fromLocationId);
        }
    }
}
