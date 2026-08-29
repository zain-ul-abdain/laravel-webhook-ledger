<?php

namespace Zain\WebhookLedger;

use Illuminate\Support\ServiceProvider;
use Zain\WebhookLedger\Console\ReplayFailedWebhooksCommand;
use Zain\WebhookLedger\Console\SweepStaleClaimsCommand;

class WebhookLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webhook-ledger.php', 'webhook-ledger');

        $this->app->singleton(WebhookLedger::class, fn ($app) => new WebhookLedger(
            $app['config']->get('webhook-ledger', [])
        ));

        $this->app->alias(WebhookLedger::class, 'webhook-ledger');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/webhook-ledger.php' => config_path('webhook-ledger.php'),
            ], 'webhook-ledger-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'webhook-ledger-migrations');

            $this->commands([
                ReplayFailedWebhooksCommand::class,
                SweepStaleClaimsCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
