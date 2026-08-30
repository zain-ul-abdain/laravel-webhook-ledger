<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Zain\WebhookLedger\Exceptions\DeferWebhook;
use Zain\WebhookLedger\Models\WebhookEvent;

class TestOrder extends Model
{
    protected $table = 'test_orders';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::dropIfExists('test_orders');
    Schema::create('test_orders', function ($table) {
        $table->id();
        $table->string('reference')->nullable();
    });
});

it('defers an event the handler cannot act on yet', function () {
    // The provider outran us: the event is valid but the local record it
    // refers to has not been written. Neither success nor failure.
    $result = ledger()->process('test', testRequest(['id' => 'evt_early']), function () {
        throw DeferWebhook::because('Order not found yet');
    });

    $row = WebhookEvent::first();

    expect($result->wasDeferred())->toBeTrue()
        ->and($result->wasProcessed())->toBeFalse()
        ->and($row->status)->toBe(WebhookEvent::STATUS_DEFERRED)
        ->and($row->last_error)->toBe('Order not found yet')
        ->and($row->processed_at)->toBeNull();
});

it('retries a deferred event on the next redelivery', function () {
    $payload = ['id' => 'evt_wait'];
    $ready = false;
    $runs = 0;

    $handler = function () use (&$ready, &$runs) {
        $runs++;
        if (! $ready) {
            throw DeferWebhook::because('not ready');
        }
    };

    $first = ledger()->process('test', testRequest($payload), $handler);
    expect($first->wasDeferred())->toBeTrue();

    // Whatever it was waiting for arrives.
    $ready = true;

    $second = ledger()->process('test', testRequest($payload), $handler);

    expect($second->wasProcessed())->toBeTrue()
        ->and($runs)->toBe(2)
        ->and(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->attempts)->toBe(2)
        ->and(WebhookEvent::first()->status)->toBe(WebhookEvent::STATUS_PROCESSED);
});

it('stays deferred across repeated redeliveries until it can be handled', function () {
    $payload = ['id' => 'evt_stuck_waiting'];
    $handler = fn () => throw DeferWebhook::because('still waiting');

    ledger()->process('test', testRequest($payload), $handler);
    ledger()->process('test', testRequest($payload), $handler);
    $third = ledger()->process('test', testRequest($payload), $handler);

    expect($third->wasDeferred())->toBeTrue()
        ->and(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->attempts)->toBe(3);
});

it('does not retry a deferred event once another worker has claimed it', function () {
    $event = WebhookEvent::create([
        'provider' => 'test',
        'event_id' => 'evt_claimed',
        'status' => WebhookEvent::STATUS_DEFERRED,
        'attempts' => 1,
        'payload' => ['id' => 'evt_claimed'],
        'claimed_at' => now()->subMinute(),
    ]);

    // A competing redelivery won the claim a moment earlier.
    WebhookEvent::query()->whereKey($event->getKey())->update([
        'status' => WebhookEvent::STATUS_PROCESSING,
        'claimed_at' => now(),
        'attempts' => 2,
    ]);

    $runs = 0;
    $result = ledger()->process('test', testRequest(['id' => 'evt_claimed']), function () use (&$runs) {
        $runs++;
    });

    expect($runs)->toBe(0)
        ->and($result->wasDuplicate())->toBeTrue()
        ->and(WebhookEvent::first()->attempts)->toBe(2);
});

it('links an event to the domain object the handler resolved', function () {
    $order = TestOrder::create(['reference' => 'ord_123']);

    ledger()->process('test', testRequest(['id' => 'evt_linked']), function ($payload, $event) use ($order) {
        $event->attachTo($order);
    });

    $row = WebhookEvent::first();

    expect($row->subject_type)->toBe(TestOrder::class)
        ->and($row->subject_id)->toBe($order->id)
        ->and($row->subject->reference)->toBe('ord_123');
});

it('can list every event received about one object', function () {
    $order = TestOrder::create(['reference' => 'ord_456']);

    foreach (['evt_a', 'evt_b', 'evt_c'] as $id) {
        ledger()->process('test', testRequest(['id' => $id]), fn ($p, $e) => $e->attachTo($order));
    }

    $history = WebhookEvent::query()
        ->where('subject_type', TestOrder::class)
        ->where('subject_id', $order->id)
        ->pluck('event_id');

    expect($history)->toHaveCount(3)->each->toStartWith('evt_');
});
