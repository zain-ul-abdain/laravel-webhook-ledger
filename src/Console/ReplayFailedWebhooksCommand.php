<?php

namespace Zain\WebhookLedger\Console;

use Illuminate\Console\Command;
use Zain\WebhookLedger\Models\WebhookEvent;

class ReplayFailedWebhooksCommand extends Command
{
    protected $signature = 'webhook-ledger:replay
        {--provider= : Restrict to one provider}
        {--id=* : Replay specific webhook_events ids}
        {--limit=100 : Maximum events to replay in one run}
        {--pretend : List what would be replayed without running anything}';

    protected $description = 'Re-run webhook events that failed, using the handler configured for their provider.';

    public function handle(): int
    {
        $query = WebhookEvent::query()
            ->where('status', WebhookEvent::STATUS_FAILED)
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->limit((int) $this->option('limit'));

        $events = $query->get();

        if ($events->isEmpty()) {
            $this->info('No failed webhook events to replay.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            $this->table(
                ['ID', 'Provider', 'Type', 'Attempts', 'Last error'],
                $events->map(fn (WebhookEvent $e) => [
                    $e->id,
                    $e->provider,
                    $e->event_type ?? '-',
                    $e->attempts,
                    str($e->last_error ?? '')->limit(60),
                ])->all()
            );

            return self::SUCCESS;
        }

        $replayed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $handler = $this->handlerFor($event->provider);

            // Replay needs a handler it can call without an HTTP request. The
            // closure you pass to WebhookLedger::process() in a controller is
            // not recoverable here, so a replayable provider must name an
            // invokable class in config.
            if ($handler === null) {
                $this->warn("Skipped #{$event->id}: no 'handler' configured for provider [{$event->provider}].");
                $skipped++;

                continue;
            }

            $event->reclaim();

            try {
                $handler($event->payload, $event);
                $event->markProcessed();
                $replayed++;
                $this->line("  <fg=green>✓</> #{$event->id} {$event->provider} {$event->event_type}");
            } catch (\Throwable $e) {
                $event->markFailed($e);
                $this->line("  <fg=red>✗</> #{$event->id} {$event->provider}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Replayed {$replayed} event(s).".($skipped > 0 ? " Skipped {$skipped}." : ''));

        return self::SUCCESS;
    }

    protected function handlerFor(string $provider): ?callable
    {
        $class = config("webhook-ledger.providers.{$provider}.handler");

        return $class === null ? null : app($class);
    }
}
