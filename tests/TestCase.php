<?php

namespace Zain\WebhookLedger\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zain\WebhookLedger\Identifiers\GenericIdentifier;
use Zain\WebhookLedger\Verifiers\SharedSecretVerifier;
use Zain\WebhookLedger\WebhookLedgerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WebhookLedgerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('webhook-ledger.stale_claim_after', 900);
        $app['config']->set('webhook-ledger.providers.test', [
            'verifier' => SharedSecretVerifier::class,
            'identifier' => GenericIdentifier::class,
            'secret' => 'test-secret',
            'header' => 'X-Webhook-Token',
            'paths' => ['id' => 'id', 'type' => 'type', 'external_id' => 'data.order_id'],
        ]);
        $app['config']->set('webhook-ledger.providers.stripe.secret', 'whsec_test');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
