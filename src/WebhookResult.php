<?php

namespace Zain\WebhookLedger;

use Zain\WebhookLedger\Models\WebhookEvent;

/**
 * The outcome of one inbound webhook.
 *
 * Both outcomes are successes as far as the provider is concerned - respond 200
 * to a duplicate. Returning an error makes the provider retry an event you have
 * already handled, which is how a small delivery hiccup becomes a retry storm.
 */
readonly class WebhookResult
{
    private function __construct(
        public string $outcome,
        public ?WebhookEvent $event,
    ) {}

    public static function processed(WebhookEvent $event): self
    {
        return new self('processed', $event);
    }

    public static function duplicate(?WebhookEvent $event): self
    {
        return new self('duplicate', $event);
    }

    public static function deferred(WebhookEvent $event): self
    {
        return new self('deferred', $event);
    }

    public function wasProcessed(): bool
    {
        return $this->outcome === 'processed';
    }

    public function wasDuplicate(): bool
    {
        return $this->outcome === 'duplicate';
    }

    /**
     * Valid, but nothing to apply it to yet — the handler asked to wait for a
     * redelivery. Still respond 200: the provider will try again, and an error
     * response only adds a retry you did not ask for.
     */
    public function wasDeferred(): bool
    {
        return $this->outcome === 'deferred';
    }
}
