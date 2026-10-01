<?php

namespace App\Events;

use App\Models\Asset;

class AssetStatusChanged extends WebhookTriggerable
{
    public function __construct(Asset $asset, string $oldStatus, string $newStatus)
    {
        parent::__construct(
            'asset.status.changed',
            $asset->organization_id,
            $asset->project_id,
            [
                'asset_id' => $asset->id,
                'system_id' => $asset->system_id,
                'serial_number' => $asset->serial_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]
        );
    }
}
