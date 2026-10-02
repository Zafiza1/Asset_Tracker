<?php

namespace App\Events;

use App\Models\Asset;
use App\Models\Location;

class AssetLocationUpdated extends WebhookTriggerable
{
    public function __construct(Asset $asset, Location $location, string $source = 'manual')
    {
        parent::__construct(
            'asset.location.updated',
            $asset->organization_id,
            $asset->project_id,
            [
                'asset_id' => $asset->id,
                'system_id' => $asset->system_id,
                'serial_number' => $asset->serial_number,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'location_type' => $location->type,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'source' => $source,
            ]
        );
    }
}
