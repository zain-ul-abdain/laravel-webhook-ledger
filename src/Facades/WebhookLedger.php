<?php

namespace Zain\WebhookLedger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Zain\WebhookLedger\WebhookResult process(string $provider, \Illuminate\Http\Request $request, callable $handler)
 *
 * @see \Zain\WebhookLedger\WebhookLedger
 */
class WebhookLedger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'webhook-ledger';
    }
}
