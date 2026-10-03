<?php

namespace App\Modules\Customer\Events;

use App\Events\WebhookTriggerable;
use App\Modules\Customer\Models\Customer;

/**
 * customer.created / customer.updated / customer.deleted.
 */
class CustomerChanged extends WebhookTriggerable
{
    public function __construct(Customer $customer, string $action)
    {
        parent::__construct(
            "customer.{$action}",
            $customer->organization_id,
            $customer->project_id,
            [
                'customer_id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'status' => $customer->status,
                'location_id' => $customer->location_id,
            ]
        );
    }
}
