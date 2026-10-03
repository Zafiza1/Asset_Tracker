<?php

namespace App\Modules\Rental\Events;

use App\Events\WebhookTriggerable;
use App\Modules\Rental\Models\Rental;

/**
 * rental.created / checked_out / extended / returned / cancelled.
 */
class RentalChanged extends WebhookTriggerable
{
    public function __construct(Rental $rental, string $action)
    {
        parent::__construct(
            "rental.{$action}",
            $rental->organization_id,
            $rental->project_id,
            [
                'rental_id' => $rental->id,
                'reference' => $rental->reference,
                'status' => $rental->status,
                'customer_id' => $rental->customer_id,
                'customer_code' => $rental->customer?->code,
                'asset_id' => $rental->asset_id,
                'system_id' => $rental->asset?->system_id,
                'serial_number' => $rental->asset?->serial_number,
                'starts_at' => $rental->starts_at?->toIso8601String(),
                'due_at' => $rental->due_at?->toIso8601String(),
                'checked_out_at' => $rental->checked_out_at?->toIso8601String(),
                'returned_at' => $rental->returned_at?->toIso8601String(),
                'rented_days' => $rental->rented_days,
                'days_late' => $rental->days_late,
                'rental_amount' => $rental->rental_amount,
                'late_fee' => $rental->late_fee,
            ]
        );
    }
}
