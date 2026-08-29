<?php

namespace Zain\WebhookLedger\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidSignatureException extends HttpException
{
    public function __construct(public readonly string $provider)
    {
        // 400, not 401. A 401 invites some providers to retry with backoff, and
        // a bad signature will never become a good one on redelivery.
        parent::__construct(400, "Webhook signature verification failed for provider [{$provider}].");
    }
}
