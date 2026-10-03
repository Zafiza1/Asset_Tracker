<?php

namespace App\Modules\Maintenance\Events;

use App\Modules\Maintenance\Models\MaintenanceRecord;

/**
 * The webhook/event payload shared by the maintenance.* events.
 */
final class MaintenancePayload
{
    public static function from(MaintenanceRecord $record): array
    {
        $asset = $record->asset;

        return [
            'maintenance_id' => $record->id,
            'asset_id' => $asset?->id,
            'system_id' => $asset?->system_id,
            'serial_number' => $asset?->serial_number,
            'title' => $record->title,
            'type' => $record->type,
            'status' => $record->status,
            'scheduled_at' => $record->scheduled_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
        ];
    }
}
