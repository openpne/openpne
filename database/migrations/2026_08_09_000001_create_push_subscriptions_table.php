<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subscribed browser, holding the Push API subscription that browser handed us: the
 * service endpoint to POST to plus the two keys its payload is encrypted with. Read and written by
 * laravel-notification-channels/webpush, so the column and index names are the package's.
 *
 * The endpoint is the subscription's byte-exact identity — a push service issues a distinct one per
 * browser — hence unique globally rather than per member: re-subscribing an endpoint under a second
 * member moves the row instead of duplicating it (a shared device changing hands). MySQL's default
 * utf8mb4_unicode_ci is case/accent-insensitive with PAD SPACE, so two endpoints differing only in
 * token case would collide on the unique index and one device would overwrite another's row; the
 * column is forced to utf8mb4_bin there. SQLite TEXT is already BINARY, and rejects that collation
 * name, so it stays on the SQLite lane's default. 500 chars keeps the unique index inside MySQL's
 * 3072-byte key limit on utf8mb4 (widened to 1024 on ascii by a later migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        Schema::create('push_subscriptions', function (Blueprint $table) use ($mysql) {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $endpoint = $table->string('endpoint', 500);
            if ($mysql) {
                $endpoint->collation('utf8mb4_bin');
            }
            $endpoint->unique();
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
