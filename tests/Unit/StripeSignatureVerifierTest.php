<?php

use Illuminate\Http\Request;
use Zain\WebhookLedger\Verifiers\StripeSignatureVerifier;

function signed(string $body, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return "t={$timestamp},v1={$signature}";
}

function stripeRequest(string $body, string $header): Request
{
    return Request::create('/webhooks/stripe', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $header,
    ], $body);
}

function verifier(array $overrides = []): StripeSignatureVerifier
{
    return new StripeSignatureVerifier(array_merge([
        'secret' => 'whsec_test',
        'header' => 'Stripe-Signature',
        'tolerance' => 300,
    ], $overrides));
}

it('accepts a correctly signed payload', function () {
    $body = '{"id":"evt_1","type":"charge.succeeded"}';

    expect(verifier()->verify(stripeRequest($body, signed($body, 'whsec_test')), $body))->toBeTrue();
});

it('rejects a tampered body', function () {
    $body = '{"id":"evt_1","amount":100}';
    $header = signed($body, 'whsec_test');
    $tampered = '{"id":"evt_1","amount":100000}';

    expect(verifier()->verify(stripeRequest($tampered, $header), $tampered))->toBeFalse();
});

it('rejects a signature made with the wrong secret', function () {
    $body = '{"id":"evt_1"}';

    expect(verifier()->verify(stripeRequest($body, signed($body, 'whsec_other')), $body))->toBeFalse();
});

it('rejects a replayed request outside the tolerance window', function () {
    $body = '{"id":"evt_1"}';
    $header = signed($body, 'whsec_test', time() - 3600);

    expect(verifier()->verify(stripeRequest($body, $header), $body))->toBeFalse();
});

it('accepts an old signature when tolerance is disabled', function () {
    $body = '{"id":"evt_1"}';
    $header = signed($body, 'whsec_test', time() - 3600);

    expect(verifier(['tolerance' => 0])->verify(stripeRequest($body, $header), $body))->toBeTrue();
});

it('accepts any valid signature when several are present during secret rotation', function () {
    $body = '{"id":"evt_1"}';
    $timestamp = time();
    $old = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_old');
    $new = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $header = "t={$timestamp},v1={$old},v1={$new}";

    expect(verifier()->verify(stripeRequest($body, $header), $body))->toBeTrue();
});

it('rejects a missing or malformed header', function () {
    $body = '{"id":"evt_1"}';

    expect(verifier()->verify(stripeRequest($body, ''), $body))->toBeFalse()
        ->and(verifier()->verify(stripeRequest($body, 'garbage'), $body))->toBeFalse()
        ->and(verifier()->verify(stripeRequest($body, 't=123'), $body))->toBeFalse();
});

it('rejects everything when no secret is configured', function () {
    $body = '{"id":"evt_1"}';

    expect(verifier(['secret' => ''])->verify(stripeRequest($body, signed($body, 'whsec_test')), $body))->toBeFalse();
});
