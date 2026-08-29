<?php

use Zain\WebhookLedger\Identifiers\GenericIdentifier;
use Zain\WebhookLedger\Verifiers\HmacSignatureVerifier;
use Zain\WebhookLedger\Verifiers\SharedSecretVerifier;
use Zain\WebhookLedger\Verifiers\StripeSignatureVerifier;

return [

    'table' => 'webhook_events',

    /*
     * How long a row may sit in "processing" before another delivery is allowed
     * to take it over. This only matters when a worker dies mid-handler: the
     * row is claimed, nothing completes it, and the unique index would reject
     * every redelivery forever.
     *
     * Set it comfortably above your slowest handler. Too low and you risk two
     * workers running the same handler concurrently; too high and a crashed
     * event waits longer than necessary.
     */
    'stale_claim_after' => 900,

    'providers' => [

        'stripe' => [
            'verifier' => StripeSignatureVerifier::class,
            'identifier' => GenericIdentifier::class,
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'header' => 'Stripe-Signature',
            'tolerance' => 300,
            'paths' => [
                'id' => 'id',
                'type' => 'type',
                'external_id' => ['data.object.payment_intent', 'data.object.id'],
            ],
        ],

        /*
         * A second Stripe account - a different region, or a separate platform
         * account. Each endpoint has its own signing secret, so each needs its
         * own entry; sharing one would silently accept events signed by either.
         */
        'stripe_connect' => [
            'verifier' => StripeSignatureVerifier::class,
            'identifier' => GenericIdentifier::class,
            'secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
            'header' => 'Stripe-Signature',
            'tolerance' => 300,
            'paths' => [
                'id' => 'id',
                'type' => 'type',
                'external_id' => ['account', 'data.object.id'],
            ],
        ],

        'generic_hmac' => [
            'verifier' => HmacSignatureVerifier::class,
            'identifier' => GenericIdentifier::class,
            'secret' => env('WEBHOOK_HMAC_SECRET'),
            'header' => 'X-Signature',
            'algo' => 'sha256',
            'prefix' => 'sha256=',
            'paths' => [
                'id' => 'event_id',
                'type' => 'event_type',
            ],
        ],

        'token_header' => [
            'verifier' => SharedSecretVerifier::class,
            'identifier' => GenericIdentifier::class,
            'secret' => env('WEBHOOK_SHARED_SECRET'),
            'header' => 'X-Webhook-Token',
            'allow_query_fallback' => false,
            'paths' => [
                'id' => 'event_id',
                'type' => 'event_type',
            ],
        ],

    ],

];
