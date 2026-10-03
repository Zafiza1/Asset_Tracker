<?php

namespace App\Modules\Delivery\Events;

use App\Events\WebhookTriggerable;
use App\Modules\Delivery\Models\Delivery;

/**
 * delivery.created / dispatched / delivered / returned / cancelled.
 */
class DeliveryChanged extends WebhookTriggerable
{
    public function __construct(Delivery $delivery, string $action, array $extra = [])
    {
        parent::__construct(
            "delivery.{$action}",
            $delivery->organization_id,
            $delivery->project_id,
            array_merge([
                'delivery_id' => $delivery->id,
                'reference' => $delivery->reference,
                'status' => $delivery->status,
                'customer_id' => $delivery->customer_id,
                'customer_code' => $delivery->customer?->code,
                'destination_location_id' => $delivery->destination_location_id,
                'assets' => $delivery->items->map(fn ($item) => [
                    'system_id' => $item->asset?->system_id,
                    'serial_number' => $item->asset?->serial_number,
                    'status' => $item->status,
                ])->values()->all(),
            ], $extra)
        );
    }
}
