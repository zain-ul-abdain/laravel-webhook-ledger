<?php

use Illuminate\Http\Request;
use Zain\WebhookLedger\Tests\TestCase;
use Zain\WebhookLedger\WebhookLedger;

uses(TestCase::class)->in('Feature', 'Unit');

function ledger(): WebhookLedger
{
    return app(WebhookLedger::class);
}

function testRequest(array $payload, string $token = 'test-secret'): Request
{
    return Request::create('/webhooks/test', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_TOKEN' => $token,
    ], json_encode($payload));
}
