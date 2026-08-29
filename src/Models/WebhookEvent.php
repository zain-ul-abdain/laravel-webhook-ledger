<?php

namespace Zain\WebhookLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string|null $event_type
 * @property string|null $external_id
 * @property string $status
 * @property int $attempts
 * @property array $payload
 * @property string|null $last_error
 * @property Carbon|null $claimed_at
 * @property Carbon|null $processed_at
 */
class WebhookEvent extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('webhook-ledger.table', 'webhook_events');
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function markFailed(\Throwable $e): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000),
        ])->save();
    }

    /**
     * Re-claim this row for another processing attempt. Used by the replay
     * command and by the stale-claim sweep.
     */
    public function reclaim(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'attempts' => $this->attempts + 1,
            'claimed_at' => now(),
        ])->save();
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * A claim is stale when a worker took the row and then died before
     * recording an outcome. Without this, such a row blocks its event forever:
     * the unique index rejects re-delivery, but nothing ever completes it.
     */
    public function claimIsStale(int $afterSeconds): bool
    {
        return $this->status === self::STATUS_PROCESSING
            && $this->claimed_at !== null
            && $this->claimed_at->addSeconds($afterSeconds)->isPast();
    }
}
