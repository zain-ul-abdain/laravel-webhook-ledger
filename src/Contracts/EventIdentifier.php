<?php

namespace Zain\WebhookLedger\Contracts;

interface EventIdentifier
{
    /**
     * Pull the provider's own unique event id out of the decoded payload.
     *
     * Return null when the provider does not send one. The ledger then falls
     * back to a content fingerprint, which deduplicates identical redeliveries
     * but cannot distinguish two legitimately identical events - so prefer a
     * real id whenever the provider offers one.
     */
    public function identify(array $payload): ?string;

    /**
     * The provider's event type, if present ("charge.refunded", and so on).
     * Stored for observability only; it never affects deduplication.
     */
    public function type(array $payload): ?string;

    /**
     * An id from your own domain that this event relates to - a payment intent,
     * an order reference, a transfer id. Indexed, so you can answer "what have
     * we ever received about this object?" without scanning the payload column.
     */
    public function externalId(array $payload): ?string;
}
