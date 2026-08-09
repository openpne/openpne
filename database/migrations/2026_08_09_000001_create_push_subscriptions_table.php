<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subscribed browser, holding the Push API subscription that browser handed us: the
 * service endpoint to POST to plus the two keys its payload is encrypted with. Read and written by
 * laravel-notification-channels/webpush, so the column and index names are the package's.
 *
 * The endpoint is the subscription's identity — a push service issues a distinct one per browser —
 * hence unique globally rather than per member: re-subscribing an endpoint under a second member
 * moves the row instead of duplicating it (a shared device changing hands). 500 chars keeps the
 * unique index inside MySQL's 3072-byte key limit on utf8mb4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', 500)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
