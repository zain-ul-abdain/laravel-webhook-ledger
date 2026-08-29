<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('webhook-ledger.table', 'webhook_events'), function (Blueprint $table) {
            $table->id();

            $table->string('provider', 64);
            $table->string('event_id', 191);
            $table->string('event_type', 191)->nullable();
            $table->string('external_id', 191)->nullable();

            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('attempts')->default(1);

            $table->json('payload');
            $table->text('last_error')->nullable();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The deduplication guarantee. This constraint - not an application-level
            // "does it exist?" check - is what makes processing exactly-once under
            // concurrent delivery. See README, "Why the unique index matters".
            $table->unique(['provider', 'event_id']);

            // Correlating an event back to your own domain object.
            $table->index(['provider', 'external_id']);

            // Supports the stale-claim sweep and failed-event replay.
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('webhook-ledger.table', 'webhook_events'));
    }
};
