<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * email_change_requests.cancel_token: the SHA-256 hash of a second raw token, carried by the cancel
 * link in the old-address security notice so its holder can void a pending change without signing in.
 * Nullable — a row created before this migration (an in-flight change during deploy) has none and its
 * already-sent notice carried no cancel link — and unique, since it is a hash and a lookup key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_change_requests', function (Blueprint $table) {
            $table->string('cancel_token')->nullable()->unique()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('email_change_requests', function (Blueprint $table) {
            // Drop the unique index before the column: SQLite cannot drop a column an index still references.
            $table->dropUnique(['cancel_token']);
            $table->dropColumn('cancel_token');
        });
    }
};
