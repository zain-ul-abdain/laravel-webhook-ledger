<?php

namespace Zain\WebhookLedger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zain\WebhookLedger\Models\WebhookEvent;

/**
 * A duplicate is normal traffic, not an incident - every provider redelivers.
 * Worth counting as a metric; not worth alerting on unless the rate jumps.
 */
class WebhookDuplicateDetected
{
    use Dispatchable;

    public function __construct(public WebhookEvent $event) {}
}
