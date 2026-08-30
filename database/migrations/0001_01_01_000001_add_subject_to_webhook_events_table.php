<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('webhook-ledger.table', 'webhook_events'), function (Blueprint $table) {
            // Lets a handler link the event to whatever it resolved to — an
            // order, a payment, a subscription — so "show me every event we
            // ever received about this object" is an indexed lookup rather
            // than a scan of the payload column.
            $table->nullableMorphs('subject');
        });
    }

    public function down(): void
    {
        Schema::table(config('webhook-ledger.table', 'webhook_events'), function (Blueprint $table) {
            $table->dropMorphs('subject');
        });
    }
};
