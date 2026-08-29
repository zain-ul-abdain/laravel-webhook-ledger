<?php

namespace Zain\WebhookLedger\Console;

use Illuminate\Console\Command;
use Zain\WebhookLedger\Models\WebhookEvent;

/**
 * Stale claims are normally recovered on redelivery - the provider retries, the
 * insert collides, and the ledger notices the claim has expired and takes over.
 *
 * This command exists for the events that are never redelivered, because the
 * provider gave up or you responded 200 before the handler died. Schedule it
 * hourly and the stuck rows surface as failures you can replay.
 */
class SweepStaleClaimsCommand extends Command
{
    protected $signature = 'webhook-ledger:sweep
        {--minutes= : Override the configured stale_claim_after threshold}
        {--pretend : List affected events without changing them}';

    protected $description = 'Mark webhook events abandoned mid-processing as failed so they can be replayed.';

    public function handle(): int
    {
        $seconds = $this->option('minutes') !== null
            ? ((int) $this->option('minutes')) * 60
            : (int) config('webhook-ledger.stale_claim_after', 900);

        $cutoff = now()->subSeconds($seconds);

        $events = WebhookEvent::query()
            ->where('status', WebhookEvent::STATUS_PROCESSING)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            $this->info('No stale claims found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$events->count()} event(s) claimed before {$cutoff->toDateTimeString()}.");

        if ($this->option('pretend')) {
            $this->table(
                ['ID', 'Provider', 'Type', 'Claimed at'],
                $events->map(fn (WebhookEvent $e) => [
                    $e->id, $e->provider, $e->event_type ?? '-', $e->claimed_at?->toDateTimeString(),
                ])->all()
            );

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $event->markFailed(new \RuntimeException(
                'Claim expired without completing; worker likely terminated mid-handler.'
            ));
        }

        $this->info("Marked {$events->count()} stale claim(s) as failed. Replay with webhook-ledger:replay.");

        return self::SUCCESS;
    }
}
