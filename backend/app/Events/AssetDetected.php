<?php

namespace App\Events;

use App\Models\Asset;
use App\Models\Device;

/**
 * asset.detected — an identification integration (RFID, BLE, NFC, QR...) saw
 * a device bound to this asset. Carries no hardware-specific fields beyond
 * the normalized source name.
 */
class AssetDetected extends WebhookTriggerable
{
    public function __construct(Asset $asset, ?Device $device, string $source, ?int $eventLogId = null)
    {
        parent::__construct(
            'asset.detected',
            $asset->organization_id,
            $asset->project_id,
            [
                'asset_id' => $asset->id,
                'system_id' => $asset->system_id,
                'serial_number' => $asset->serial_number,
                'device_id' => $device?->id,
                'device_system_id' => $device?->system_id,
                'source' => $source,
                'event_log_id' => $eventLogId,
            ]
        );
    }
}
