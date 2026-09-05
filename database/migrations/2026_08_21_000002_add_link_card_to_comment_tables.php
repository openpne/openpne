<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * InnoDB backs a foreign key with an index and SQLite backs none, so the index is added on the
 * SQLite lane only.
 */
return new class extends Migration
{
    private const TABLES = ['diary_comments', 'group_topic_comments', 'group_event_comments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('link_card_id')->nullable()->constrained('link_cards')->nullOnDelete();
                $table->timestamp('link_card_synced_at')->nullable();
            });

            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table($table, function (Blueprint $table): void {
                    $table->index('link_card_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropIndex(['link_card_id']);
                });
            }

            Schema::table($table, function (Blueprint $table): void {
                // The constraint goes before the column: InnoDB adopts the index it created for the
                // foreign key, and refuses to drop it while the constraint still exists (errno 1553).
                $table->dropForeign(['link_card_id']);
                $table->dropColumn(['link_card_id', 'link_card_synced_at']);
            });
        }
    }
};
