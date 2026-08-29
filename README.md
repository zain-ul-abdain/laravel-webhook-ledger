# Laravel Webhook Ledger

**Exactly-once webhook processing for Laravel.** Signature verification, atomic deduplication, and a durable event log — for Stripe, PayPal, and anything else that posts JSON at you.

[![tests](https://github.com/zain-ul-abdain/laravel-webhook-ledger/actions/workflows/tests.yml/badge.svg)](https://github.com/zain-ul-abdain/laravel-webhook-ledger/actions)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

```bash
composer require zain-ul-abdain/laravel-webhook-ledger
php artisan migrate
```

---

## The problem

Every payment provider will deliver the same webhook to you more than once. Not occasionally — routinely. They retry on timeout, on a 500, on a connection reset, and sometimes on a response they simply didn't like. Stripe retries for up to three days. PayPal does too.

If your handler credits an account, ships an order, or releases a payout, running it twice is not a cosmetic bug. It is money.

So most codebases reach for the obvious guard:

```php
// This does not work.
if (WebhookEvent::where('event_id', $id)->exists()) {
    return response('duplicate', 200);
}

WebhookEvent::create(['event_id' => $id, ...]);
$this->handle($payload);
```

## Why that doesn't work

There is a gap between the `exists()` check and the `create()`. Under concurrent delivery — which is exactly what a retry storm produces — two requests can both execute the check before either executes the insert:

```
  request A          request B
  ─────────          ─────────
  exists()? no
                     exists()? no
  insert
  handle()  ←── credits the account
                     insert
                     handle()  ←── credits it again
```

The window is small. It is not small enough. Any provider retrying an event a few hundred milliseconds apart will find it, and you will discover this the way everyone discovers it: from a customer, about a double refund.

**The only component that can arbitrate this is the database.** So this package lets it:

```php
$table->unique(['provider', 'event_id']);
```

and then treats the constraint violation as the duplicate signal rather than as an error:

```php
try {
    $event = WebhookEvent::create([...]);   // the claim
} catch (UniqueConstraintViolationException) {
    return $this->handleExisting(...);      // someone else got there first
}

$handler($payload, $event);                 // runs at most once
```

There is no window, because there is no gap. The insert either succeeds or it doesn't, and the database decides atomically.

---

## Usage

```php
use Zain\WebhookLedger\Facades\WebhookLedger;

Route::post('/webhooks/stripe', function (Request $request) {
    $result = WebhookLedger::process('stripe', $request, function (array $payload, $event) {
        match ($payload['type']) {
            'checkout.session.completed' => $orders->markPaid($payload['data']['object']),
            'charge.refunded'            => $orders->refund($payload['data']['object']),
            default                      => null,
        };
    });

    return response()->noContent();
});
```

The closure runs at most once per event, across concurrent requests, across queue workers, and across redeliveries days apart. Everything else — verification, deduplication, persistence, failure recording — happens around it.

**Always respond `200` to a duplicate.** Returning an error makes the provider retry an event you have already handled, which is how one delivery hiccup becomes a retry storm.

### Configuration

```bash
php artisan vendor:publish --tag=webhook-ledger-config
```

```php
'providers' => [
    'stripe' => [
        'verifier'   => StripeSignatureVerifier::class,
        'secret'     => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance'  => 300,
        'paths'      => [
            'id'          => 'id',
            'type'        => 'type',
            'external_id' => ['data.object.payment_intent', 'data.object.id'],
        ],
    ],
],
```

`paths` are dot-paths into the payload. A list of candidates is tried in order — useful when a provider moves a field between API versions and you're receiving both.

Running two Stripe accounts (a second region, or a platform account for Connect)? Give each its own provider entry. They have different signing secrets, and sharing one entry would silently accept events signed by either.

---

## What else it handles

### Signature verification comes first

Verification happens **before** any database write. An unauthenticated caller must not be able to create rows in the table your entire correctness guarantee depends on — that's a free denial-of-service against your own deduplication.

Three verifiers ship with the package:

| Verifier | For |
|---|---|
| `StripeSignatureVerifier` | Stripe's `t=…,v1=…` scheme, implemented directly — no SDK dependency |
| `HmacSignatureVerifier` | The common case: `HMAC(body, secret)` in a header, with configurable algorithm and prefix |
| `SharedSecretVerifier` | A static token in a header. Weak, but it's what some providers offer |

All comparisons use `hash_equals`, so timing doesn't leak how much of a signature was correct.

The Stripe verifier enforces a timestamp tolerance. Without it, anyone who captures one validly-signed request can replay it forever.

> **A note on raw bodies.** Signatures are computed over the exact bytes the provider sent. Never reconstruct the body with `json_encode(json_decode($body))` — key order, whitespace, unicode escaping, and float formatting all drift, and your verification will fail intermittently in ways that are genuinely unpleasant to debug.

### Providers that send no event id

Some don't. The ledger falls back to a content fingerprint — `sha256(provider + raw body)` — which deduplicates identical redeliveries correctly.

Know the limitation: two genuinely distinct but byte-identical events collapse into one. That's the right trade for retries and the wrong one for, say, a `ping` sent twice on purpose. Use a real event id wherever the provider offers one.

### Workers that die mid-handler

A row is claimed as `processing` before the handler runs. If the process is killed at that moment — deploy, OOM, timeout — the row is stuck: nothing completes it, and the unique index rejects every redelivery. The event is now permanently lost.

So a claim expires. After `stale_claim_after` seconds, the next redelivery takes it over and increments `attempts`:

```php
'stale_claim_after' => 900,
```

Set it comfortably above your slowest handler. Too low risks two workers running the same handler concurrently; too high leaves crashed events waiting.

For events the provider never redelivers, a scheduled sweep converts stuck claims into failures you can see and replay:

```php
Schedule::command('webhook-ledger:sweep')->hourly();
```

### Failures are explicit, not silent

A handler that throws marks the event `failed` and rethrows. The exception is yours to log, alert on, and handle.

Redelivery of a failed event is treated as a **duplicate**, not a retry. Retrying is a deliberate, observable act — not something that happens to occur because the provider happened to try again:

```bash
php artisan webhook-ledger:replay --pretend
php artisan webhook-ledger:replay --provider=stripe --limit=50
php artisan webhook-ledger:replay --id=1041 --id=1042
```

Replay needs a handler it can invoke without an HTTP request, so add an invokable class to that provider's config:

```php
'stripe' => [
    // ...
    'handler' => App\Webhooks\StripeHandler::class,
],
```

### Events

`WebhookProcessed`, `WebhookDuplicateDetected`, and `WebhookFailed` are dispatched for observability.

Duplicates are normal traffic — every provider redelivers. Worth a counter; not worth an alert unless the rate jumps.

---

## Schema

```php
$table->string('provider', 64);
$table->string('event_id', 191);
$table->string('event_type', 191)->nullable();
$table->string('external_id', 191)->nullable();
$table->string('status', 16);          // processing | processed | failed
$table->unsignedSmallInteger('attempts');
$table->json('payload');
$table->text('last_error')->nullable();
$table->timestamp('claimed_at')->nullable();
$table->timestamp('processed_at')->nullable();

$table->unique(['provider', 'event_id']);   // the guarantee
$table->index(['provider', 'external_id']); // "what have we received about this order?"
$table->index(['status', 'claimed_at']);    // sweep and replay
```

The stored payload is worth as much as the deduplication. When a customer disputes what happened, the raw event as the provider sent it is the only record that settles it.

---

## Testing

```bash
composer install
vendor/bin/pest
```

The suite covers concurrent redelivery, tampered and replayed signatures, secret rotation, fingerprint fallback, stale-claim takeover, and failure recording.

---

## Requirements

PHP 8.2+ · Laravel 12 or 13 · any database with unique constraint support

> Laravel 11 is not supported: every 11.x release currently carries open security advisories, so Composer refuses to install it under default policy.

## License

MIT. Built by [Zain](https://github.com/zain-ul-abdain) — backend engineer working on payments infrastructure.
