<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The column and index names are laravel-notification-channels/webpush's, which reads and writes
 * this table. MySQL's default utf8mb4_unicode_ci is case- and accent-insensitive, so two endpoints
 * differing only in token case would collide on the unique index and one device would overwrite
 * another's row; utf8mb4_bin avoids that, and SQLite TEXT is already binary and rejects the
 * collation name.
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
