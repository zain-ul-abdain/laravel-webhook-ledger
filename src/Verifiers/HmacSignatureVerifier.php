<?php

namespace Zain\WebhookLedger\Verifiers;

use Illuminate\Http\Request;
use Zain\WebhookLedger\Contracts\SignatureVerifier;

/**
 * The common case: provider sends HMAC(raw body, secret) in a header.
 *
 * Configure the algorithm, header name, and an optional prefix that some
 * providers prepend ("sha256=" is the usual one).
 */
class HmacSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected array $config) {}

    public function verify(Request $request, string $rawBody): bool
    {
        $secret = (string) ($this->config['secret'] ?? '');
        $header = (string) ($this->config['header'] ?? 'X-Signature');
        $algo = (string) ($this->config['algo'] ?? 'sha256');
        $prefix = (string) ($this->config['prefix'] ?? '');

        $received = (string) $request->header($header, '');

        if ($secret === '' || $received === '') {
            return false;
        }

        if ($prefix !== '' && str_starts_with($received, $prefix)) {
            $received = substr($received, strlen($prefix));
        }

        $expected = hash_hmac($algo, $rawBody, $secret);

        return hash_equals($expected, $received);
    }
}
