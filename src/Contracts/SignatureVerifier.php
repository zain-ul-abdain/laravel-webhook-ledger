<?php

namespace Zain\WebhookLedger\Contracts;

use Illuminate\Http\Request;

interface SignatureVerifier
{
    /**
     * Decide whether this request genuinely came from the provider.
     *
     * Implementations receive the raw request body as a string. Never re-encode
     * the parsed request to reconstruct it: signatures are computed over the
     * exact bytes sent, and json_encode(json_decode($body)) is not byte-stable
     * (key order, whitespace, unicode escaping, and float formatting all drift).
     */
    public function verify(Request $request, string $rawBody): bool;
}
