<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An endpoint is a URL, so on ascii the unique index fits 1024 characters under MySQL's 3072-byte
 * key limit and ascii_bin keeps the byte-exact identity utf8mb4_bin gave it. Rows the target column
 * cannot hold are deleted first in either direction, a subscription being disposable, and on SQLite,
 * which declares a bare varchar, only that cleanup runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Frozen as of this migration rather than read from the rule, and matched on the character
        // class so no row the rule refuses survives.
        $this->deleteWhere(fn (string $endpoint): bool => preg_match('/\A[\x21-\x7e]{1,1024}\z/', $endpoint) !== 1);
        $this->resize(1024, 'ascii', 'ascii_bin');
    }

    public function down(): void
    {
        $this->deleteWhere(fn (string $endpoint): bool => mb_strlen($endpoint) > 500);
        $this->resize(500, 'utf8mb4', 'utf8mb4_bin');
    }

    private function resize(int $length, string $charset, string $collation): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) use ($length, $charset, $collation): void {
            $table->string('endpoint', $length)->charset($charset)->collation($collation)->change();
        });
    }

    /** @param  Closure(string): bool  $doesNotFit */
    private function deleteWhere(Closure $doesNotFit): void
    {
        DB::table('push_subscriptions')
            ->select(['id', 'endpoint'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($doesNotFit): void {
                $ids = $rows->filter(fn (object $row): bool => $doesNotFit((string) $row->endpoint))->pluck('id');

                if ($ids->isNotEmpty()) {
                    DB::table('push_subscriptions')->whereIn('id', $ids)->delete();
                }
            });
    }
};
