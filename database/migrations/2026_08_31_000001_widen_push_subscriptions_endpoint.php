<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some push services issue endpoints longer than 500 characters, and a device holding one could not
 * register — worse, the 422 read as a definitive refusal and shed the subscription on every reconcile.
 *
 * 500 was as wide as a utf8mb4 unique index fits under MySQL's 3072-byte key limit. An endpoint is a
 * URL, so ASCII (App\Rules\PushEndpoint now refuses anything else): on ascii the same index fits 1024,
 * and ascii_bin keeps the byte-exact identity utf8mb4_bin gave it. One MODIFY, with the unique index
 * left in place — both forms sit well under the key limit — so the change either lands whole or
 * leaves the table as it was. Rows the target column could not hold are deleted first, in either
 * direction: a subscription is disposable (the device re-registers on its next visit), and a row that
 * failed the ALTER would strand the migration instead.
 *
 * SQLite declares the column as a bare varchar, no width and no charset, so only the cleanup runs there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deleteWhere(fn (string $endpoint): bool => strlen($endpoint) > 1024 || preg_match('/[\x80-\xFF]/', $endpoint) === 1);
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
