<?php

use Illuminate\Support\Facades\DB;
use Zain\WebhookLedger\Models\WebhookEvent;

/**
 * The failures covered here are invisible on SQLite, or invisible to a
 * sequential test that only looks concurrent. Run this file against PostgreSQL
 * as well as SQLite — see docker-compose.yml.
 */
it('survives a duplicate while the caller holds an open transaction', function () {
    $payload = ['id' => 'evt_txn'];

    ledger()->process('test', testRequest($payload), fn () => null);

    // Wrapping the handler in a transaction is the normal thing to do — you want
    // your own writes atomic with the ledger row. On PostgreSQL a constraint
    // violation aborts the whole transaction, so without a savepoint around the
    // insert the recovery read fails with 25P02 and every duplicate becomes a
    // 500 on the most common path in production.
    $result = DB::transaction(function () use ($payload) {
        return ledger()->process('test', testRequest($payload), fn () => null);
    });

    expect($result->wasDuplicate())->toBeTrue()
        ->and(WebhookEvent::count())->toBe(1);
});

it('refuses a takeover once another worker has claimed the row', function () {
    $event = WebhookEvent::create([
        'provider' => 'test',
        'event_id' => 'evt_contended',
        'status' => WebhookEvent::STATUS_PROCESSING,
        'attempts' => 1,
        'payload' => ['id' => 'evt_contended'],
        'claimed_at' => now()->subHours(2),
    ]);

    // A competing worker won the takeover a moment earlier: it bumped
    // claimed_at and incremented attempts. Our request read the row while it
    // was still stale, so a read-then-write takeover would proceed anyway and
    // run the handler a second time.
    //
    // Because the staleness predicate lives in the UPDATE, this attempt affects
    // zero rows and correctly stands down.
    WebhookEvent::query()->whereKey($event->getKey())->update([
        'claimed_at' => now(),
        'attempts' => 2,
    ]);

    $runs = 0;

    $result = ledger()->process('test', testRequest(['id' => 'evt_contended']), function () use (&$runs) {
        $runs++;
    });

    expect($runs)->toBe(0)
        ->and($result->wasDuplicate())->toBeTrue()
        ->and(WebhookEvent::first()->attempts)->toBe(2);
});

it('increments attempts exactly once when a takeover succeeds', function () {
    WebhookEvent::create([
        'provider' => 'test',
        'event_id' => 'evt_takeover',
        'status' => WebhookEvent::STATUS_PROCESSING,
        'attempts' => 3,
        'payload' => ['id' => 'evt_takeover'],
        'claimed_at' => now()->subHours(2),
    ]);

    $result = ledger()->process('test', testRequest(['id' => 'evt_takeover']), fn () => null);

    expect($result->wasProcessed())->toBeTrue()
        ->and(WebhookEvent::first()->attempts)->toBe(4)
        ->and(WebhookEvent::first()->status)->toBe(WebhookEvent::STATUS_PROCESSED);
});

it('does not mask the handler exception when recording the failure also fails', function () {
    // Drop the table out from under the ledger after the row is claimed, so the
    // markFailed() write cannot succeed. The caller must still receive the
    // handler's exception rather than a database error about the bookkeeping.
    expect(fn () => ledger()->process('test', testRequest(['id' => 'evt_mask']), function () {
        DB::statement('DROP TABLE webhook_events');

        throw new RuntimeException('the real problem');
    }))->toThrow(RuntimeException::class, 'the real problem');
});
