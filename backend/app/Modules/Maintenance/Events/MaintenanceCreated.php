<?php

namespace App\Modules\Maintenance\Events;

use App\Events\WebhookTriggerable;
use App\Modules\Maintenance\Models\MaintenanceRecord;

class MaintenanceCreated extends WebhookTriggerable
{
    public function __construct(MaintenanceRecord $record)
    {
        parent::__construct(
            'maintenance.created',
            $record->organization_id,
            $record->project_id,
            MaintenancePayload::from($record)
        );
    }
}
