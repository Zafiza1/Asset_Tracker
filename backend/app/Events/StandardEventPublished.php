<?php

namespace App\Events;

/**
 * A standard-contract event that Core stores and forwards but does not act
 * on itself — e.g. maintenance.created published by a module or an external
 * system (Level 3: Custom Event). Delivered to subscribed webhooks.
 */
class StandardEventPublished extends WebhookTriggerable
{
}
