<?php

namespace Zain\WebhookLedger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Zain\WebhookLedger\Models\WebhookEvent;

class WebhookProcessed
{
    use Dispatchable;

    public function __construct(public WebhookEvent $event) {}
}
