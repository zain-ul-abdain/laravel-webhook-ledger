<?php

namespace Zain\WebhookLedger\Exceptions;

use RuntimeException;

/**
 * Thrown by a handler when the event is valid but not yet actionable.
 *
 * The canonical case is a provider that outruns you: Stripe can deliver
 * checkout.session.completed before your redirect handler has committed the
 * local order row, so the handler has a legitimate event and nothing to apply
 * it to. That is neither success nor failure — treating it as success loses the
 * event, and treating it as failure fills your alerts with something that
 * resolves itself on the next redelivery.
 *
 * A deferred event is left retriable: the next redelivery runs the handler
 * again. Providers retry for days, which is usually longer than whatever you
 * were waiting for.
 */
class DeferWebhook extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
