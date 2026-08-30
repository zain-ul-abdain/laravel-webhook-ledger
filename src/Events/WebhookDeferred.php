<?php

namespace Zain\WebhookLedger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zain\WebhookLedger\Models\WebhookEvent;

/**
 * A deferral is expected traffic when a provider can outrun your own writes.
 * Worth a counter; worth an alert only if the same event defers repeatedly,
 * which means whatever it was waiting for never arrived.
 */
class WebhookDeferred
{
    use Dispatchable;

    public function __construct(public WebhookEvent $event, public string $reason) {}
}
