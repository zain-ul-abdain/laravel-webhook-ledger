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
        $app['config']->set('database.connections.testing', $this->connectionConfig());

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

    /**
     * The suite runs against whichever driver DB_DRIVER names, defaulting to
     * SQLite. Constraint-violation behaviour inside a transaction differs by
     * engine - PostgreSQL aborts the entire transaction where MySQL and SQLite
     * do not - so correctness here cannot be established on one driver alone.
     */
    protected function connectionConfig(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', 'postgres'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'testing'),
                'password' => env('DB_PASSWORD', 'testing'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', 'mysql'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'testing'),
                'password' => env('DB_PASSWORD', 'testing'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
