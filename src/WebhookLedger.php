<?php

namespace Zain\WebhookLedger;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Zain\WebhookLedger\Contracts\EventIdentifier;
use Zain\WebhookLedger\Contracts\SignatureVerifier;
use Zain\WebhookLedger\Events\WebhookDuplicateDetected;
use Zain\WebhookLedger\Events\WebhookFailed;
use Zain\WebhookLedger\Events\WebhookProcessed;
use Zain\WebhookLedger\Exceptions\InvalidSignatureException;
use Zain\WebhookLedger\Exceptions\UnknownProviderException;
use Zain\WebhookLedger\Identifiers\GenericIdentifier;
use Zain\WebhookLedger\Models\WebhookEvent;

class WebhookLedger
{
    public function __construct(protected array $config) {}

    /**
     * Verify, deduplicate, and process one inbound webhook.
     *
     * The handler receives the decoded payload and the persisted event row. It
     * runs at most once per (provider, event_id) - including across concurrent
     * requests, across queue workers, and across redeliveries days apart.
     *
     * @param  callable(array, WebhookEvent): void  $handler
     */
    public function process(string $provider, Request $request, callable $handler): WebhookResult
    {
        $settings = $this->settingsFor($provider);
        $rawBody = $request->getContent();

        // Verify before writing anything. An unauthenticated request should not
        // be able to create rows in your database - that is a free denial-of-
        // service against the table the whole guarantee depends on.
        if (! $this->verifierFor($provider, $settings)->verify($request, $rawBody)) {
            throw new InvalidSignatureException($provider);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            $payload = [];
        }

        $identifier = $this->identifierFor($provider, $settings);
        $eventId = $identifier->identify($payload) ?? $this->fingerprint($provider, $rawBody);

        return $this->claimAndRun($provider, $eventId, $identifier, $payload, $handler);
    }

    /**
     * Claim the event, then run the handler.
     *
     * The claim is an INSERT that either succeeds or violates the unique index.
     * That is deliberate and it is the heart of the package: a check-then-insert
     * ("if (! exists) { create }") has a race window between the two statements
     * wide enough for two concurrent deliveries of the same event to both pass
     * the check and both process. The database is the only component that can
     * arbitrate this, so we let it, and treat the violation as our duplicate
     * signal rather than as an error.
     *
     * @param  callable(array, WebhookEvent): void  $handler
     */
    protected function claimAndRun(
        string $provider,
        string $eventId,
        EventIdentifier $identifier,
        array $payload,
        callable $handler
    ): WebhookResult {
        try {
            $event = WebhookEvent::create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $identifier->type($payload),
                'external_id' => $identifier->externalId($payload),
                'status' => WebhookEvent::STATUS_PROCESSING,
                'attempts' => 1,
                'payload' => $payload,
                'claimed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->handleExisting($provider, $eventId, $payload, $handler);
        }

        return $this->run($event, $payload, $handler);
    }

    /**
     * We lost the insert race, or this event genuinely arrived before.
     *
     * Three cases, and only one of them is a plain duplicate:
     *  - already processed  -> acknowledge, do nothing
     *  - previously failed  -> leave it for the replay command, do not silently retry
     *  - stale claim        -> a worker died mid-handler; nothing will ever finish
     *                          this event unless we pick it back up here
     *
     * @param  callable(array, WebhookEvent): void  $handler
     */
    protected function handleExisting(string $provider, string $eventId, array $payload, callable $handler): WebhookResult
    {
        $event = WebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();

        // Vanishingly rare: the row was deleted between the failed insert and
        // this read. Treat as a duplicate rather than looping.
        if ($event === null) {
            return WebhookResult::duplicate(null);
        }

        if ($event->claimIsStale((int) ($this->config['stale_claim_after'] ?? 900))) {
            $event->reclaim();

            return $this->run($event, $payload, $handler);
        }

        WebhookDuplicateDetected::dispatch($event);

        return WebhookResult::duplicate($event);
    }

    /**
     * @param  callable(array, WebhookEvent): void  $handler
     */
    protected function run(WebhookEvent $event, array $payload, callable $handler): WebhookResult
    {
        try {
            $handler($payload, $event);
        } catch (\Throwable $e) {
            $event->markFailed($e);

            WebhookFailed::dispatch($event, $e);

            throw $e;
        }

        $event->markProcessed();

        WebhookProcessed::dispatch($event);

        return WebhookResult::processed($event);
    }

    /**
     * Deduplication key for providers that send no event id of their own.
     *
     * Note the limitation: two byte-identical events that are genuinely distinct
     * collapse into one. That is the correct trade for retries, and the wrong
     * trade for, say, a "ping" event sent twice on purpose. Providers that give
     * you a real id should always use it.
     */
    protected function fingerprint(string $provider, string $rawBody): string
    {
        return 'fp_'.hash('sha256', $provider.':'.$rawBody);
    }

    protected function verifierFor(string $provider, array $settings): SignatureVerifier
    {
        return app($settings['verifier'], ['config' => $settings]);
    }

    protected function identifierFor(string $provider, array $settings): EventIdentifier
    {
        $class = $settings['identifier'] ?? GenericIdentifier::class;

        return app($class, ['config' => $settings]);
    }

    protected function settingsFor(string $provider): array
    {
        $settings = $this->config['providers'][$provider] ?? null;

        if ($settings === null) {
            throw new UnknownProviderException($provider);
        }

        return $settings;
    }
}
