<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some push services issue endpoints longer than 500 characters, and a device holding one could not
 * register — worse, the 422 read as a definitive refusal and shed the subscription on every reconcile.
 *
 * 500 was as wide as a utf8mb4 unique index fits under MySQL's 3072-byte key limit. An endpoint is a
 * URL, so ASCII by definition (App\Rules\PushEndpoint refuses anything else): on ascii the same index
 * fits 1024, and ascii_bin keeps the byte-exact identity utf8mb4_bin gave it. SQLite TEXT has neither
 * a key limit nor a charset to set, so only the declared width moves there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(1024, 'ascii', 'ascii_bin');
    }

    public function down(): void
    {
        $this->resize(500, 'utf8mb4', 'utf8mb4_bin');
    }

    private function resize(int $length, string $charset, string $collation): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        // The unique index is dropped first and re-created on the changed column: MySQL will not
        // widen a column past what its indexed key can hold, so the index cannot ride along.
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['endpoint']);
        });

        Schema::table('push_subscriptions', function (Blueprint $table) use ($mysql, $length, $charset, $collation): void {
            $endpoint = $table->string('endpoint', $length);
            if ($mysql) {
                $endpoint->charset($charset)->collation($collation);
            }
            $endpoint->unique()->change();
        });
    }
};
