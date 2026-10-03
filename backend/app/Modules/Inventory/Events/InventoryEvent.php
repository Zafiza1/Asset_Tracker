<?php

namespace App\Modules\Inventory\Events;

use App\Events\WebhookTriggerable;

/**
 * inventory.low_stock and inventory.count.* events.
 */
class InventoryEvent extends WebhookTriggerable
{
}
