<?php

namespace App\Events;

use App\Models\Asset;

class AssetCreated extends WebhookTriggerable
{
    public function __construct(Asset $asset)
    {
        parent::__construct(
            'asset.created',
            $asset->organization_id,
            $asset->project_id,
            [
                'asset_id' => $asset->id,
                'system_id' => $asset->system_id,
                'serial_number' => $asset->serial_number,
                'name' => $asset->name,
                'asset_type' => $asset->asset_type,
                'status' => $asset->status,
            ]
        );
    }
}
