<?php

namespace Zain\WebhookLedger\Verifiers;

use Illuminate\Http\Request;
use Zain\WebhookLedger\Contracts\SignatureVerifier;

/**
 * Stripe's scheme, implemented directly rather than through the SDK, so this
 * package stays dependency-free.
 *
 * The header looks like: t=1690000000,v1=abc...,v1=def...
 * The signed payload is "{timestamp}.{raw body}", HMAC-SHA256 with the endpoint
 * secret. Multiple v1 entries appear while you are rotating secrets, so any one
 * matching is a pass.
 */
class StripeSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected array $config) {}

    public function verify(Request $request, string $rawBody): bool
    {
        $secret = (string) ($this->config['secret'] ?? '');
        $header = (string) $request->header($this->config['header'] ?? 'Stripe-Signature', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        // Reject replays of an old, validly-signed request. Without this, anyone
        // who captures one request body can resend it indefinitely.
        $tolerance = (int) ($this->config['tolerance'] ?? 300);

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            // hash_equals, not ===, so comparison time does not leak how much
            // of the signature was correct.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int|null, 1: array<int, string>}
     */
    protected function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
