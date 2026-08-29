<?php

namespace Zain\WebhookLedger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;
use Zain\WebhookLedger\Models\WebhookEvent;

class WebhookFailed
{
    use Dispatchable;

    public function __construct(public WebhookEvent $event, public Throwable $exception) {}
}
