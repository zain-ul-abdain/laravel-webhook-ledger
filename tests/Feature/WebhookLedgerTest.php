<?php

use Illuminate\Http\Request;
use Zain\WebhookLedger\Exceptions\InvalidSignatureException;
use Zain\WebhookLedger\Exceptions\UnknownProviderException;
use Zain\WebhookLedger\Models\WebhookEvent;
use Zain\WebhookLedger\WebhookLedger;

function ledger(): WebhookLedger
{
    return app(WebhookLedger::class);
}

function testRequest(array $payload, string $token = 'test-secret'): Request
{
    $body = json_encode($payload);

    $request = Request::create('/webhooks/test', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_TOKEN' => $token,
    ], $body);

    return $request;
}

it('processes a valid event exactly once', function () {
    $runs = 0;

    $result = ledger()->process('test', testRequest([
        'id' => 'evt_1',
        'type' => 'payment.succeeded',
        'data' => ['order_id' => 'ord_99'],
    ]), function () use (&$runs) {
        $runs++;
    });

    expect($runs)->toBe(1)
        ->and($result->wasProcessed())->toBeTrue();

    $row = WebhookEvent::first();

    expect($row->provider)->toBe('test')
        ->and($row->event_id)->toBe('evt_1')
        ->and($row->event_type)->toBe('payment.succeeded')
        ->and($row->external_id)->toBe('ord_99')
        ->and($row->status)->toBe(WebhookEvent::STATUS_PROCESSED)
        ->and($row->processed_at)->not->toBeNull();
});

it('does not run the handler twice for a redelivered event', function () {
    $runs = 0;
    $payload = ['id' => 'evt_dup', 'type' => 'payment.succeeded'];

    $handler = function () use (&$runs) {
        $runs++;
    };

    $first = ledger()->process('test', testRequest($payload), $handler);
    $second = ledger()->process('test', testRequest($payload), $handler);

    expect($runs)->toBe(1)
        ->and($first->wasProcessed())->toBeTrue()
        ->and($second->wasDuplicate())->toBeTrue()
        ->and(WebhookEvent::count())->toBe(1);
});

it('rejects an invalid signature without writing a row', function () {
    expect(fn () => ledger()->process(
        'test',
        testRequest(['id' => 'evt_bad'], token: 'wrong-secret'),
        fn () => null
    ))->toThrow(InvalidSignatureException::class);

    // The important half of this assertion: an unauthenticated caller must not
    // be able to grow the table.
    expect(WebhookEvent::count())->toBe(0);
});

it('throws for a provider that is not configured', function () {
    expect(fn () => ledger()->process('nope', testRequest(['id' => 'x']), fn () => null))
        ->toThrow(UnknownProviderException::class);
});

it('falls back to a content fingerprint when the payload has no id', function () {
    $result = ledger()->process('test', testRequest(['type' => 'ping']), fn () => null);

    expect($result->wasProcessed())->toBeTrue()
        ->and(WebhookEvent::first()->event_id)->toStartWith('fp_');
});

it('deduplicates fingerprinted events with identical bodies', function () {
    $runs = 0;
    $handler = function () use (&$runs) {
        $runs++;
    };

    ledger()->process('test', testRequest(['type' => 'ping']), $handler);
    ledger()->process('test', testRequest(['type' => 'ping']), $handler);

    expect($runs)->toBe(1)->and(WebhookEvent::count())->toBe(1);
});

it('marks the event failed and rethrows when the handler blows up', function () {
    $boom = new RuntimeException('downstream unavailable');

    expect(fn () => ledger()->process(
        'test',
        testRequest(['id' => 'evt_fail']),
        fn () => throw $boom
    ))->toThrow(RuntimeException::class, 'downstream unavailable');

    $row = WebhookEvent::first();

    expect($row->status)->toBe(WebhookEvent::STATUS_FAILED)
        ->and($row->last_error)->toContain('downstream unavailable')
        ->and($row->processed_at)->toBeNull();
});

it('does not silently retry a previously failed event on redelivery', function () {
    $payload = ['id' => 'evt_once_failed'];

    try {
        ledger()->process('test', testRequest($payload), fn () => throw new RuntimeException('nope'));
    } catch (RuntimeException) {
        // expected
    }

    $runs = 0;
    $result = ledger()->process('test', testRequest($payload), function () use (&$runs) {
        $runs++;
    });

    // Redelivery of a failed event is still a duplicate. Retrying belongs to the
    // replay command, where it is deliberate and observable - not to whichever
    // redelivery happens to arrive next.
    expect($runs)->toBe(0)->and($result->wasDuplicate())->toBeTrue();
});

it('takes over a claim abandoned by a dead worker', function () {
    WebhookEvent::create([
        'provider' => 'test',
        'event_id' => 'evt_stuck',
        'status' => WebhookEvent::STATUS_PROCESSING,
        'attempts' => 1,
        'payload' => ['id' => 'evt_stuck'],
        'claimed_at' => now()->subHours(2),
    ]);

    $runs = 0;

    $result = ledger()->process('test', testRequest(['id' => 'evt_stuck']), function () use (&$runs) {
        $runs++;
    });

    $row = WebhookEvent::first();

    expect($runs)->toBe(1)
        ->and($result->wasProcessed())->toBeTrue()
        ->and($row->status)->toBe(WebhookEvent::STATUS_PROCESSED)
        ->and($row->attempts)->toBe(2);
});

it('leaves a fresh claim alone', function () {
    WebhookEvent::create([
        'provider' => 'test',
        'event_id' => 'evt_inflight',
        'status' => WebhookEvent::STATUS_PROCESSING,
        'attempts' => 1,
        'payload' => ['id' => 'evt_inflight'],
        'claimed_at' => now()->subSeconds(5),
    ]);

    $runs = 0;

    $result = ledger()->process('test', testRequest(['id' => 'evt_inflight']), function () use (&$runs) {
        $runs++;
    });

    expect($runs)->toBe(0)->and($result->wasDuplicate())->toBeTrue();
});
