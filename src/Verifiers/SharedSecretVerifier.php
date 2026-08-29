<?php

namespace Zain\WebhookLedger\Verifiers;

use Illuminate\Http\Request;
use Zain\WebhookLedger\Contracts\SignatureVerifier;

/**
 * A static token in a header - the weakest scheme still worth calling
 * verification, and unfortunately what several payment providers offer.
 *
 * It proves the caller knows a secret; it does not bind the secret to the body,
 * so it gives you no integrity guarantee and no replay protection. Prefer HMAC
 * whenever the provider supports it.
 *
 * Reading the token from the query string is supported for providers that
 * cannot send custom headers, but it is off by default: query strings turn up
 * in access logs, proxy logs, and referrer headers.
 */
class SharedSecretVerifier implements SignatureVerifier
{
    public function __construct(protected array $config) {}

    public function verify(Request $request, string $rawBody): bool
    {
        $expected = (string) ($this->config['secret'] ?? '');

        if ($expected === '') {
            return false;
        }

        $received = (string) $request->header($this->config['header'] ?? 'X-Webhook-Token', '');

        if ($received === '' && ($this->config['allow_query_fallback'] ?? false)) {
            $received = (string) $request->query($this->config['query_key'] ?? 'token', '');
        }

        return $received !== '' && hash_equals($expected, $received);
    }
}
