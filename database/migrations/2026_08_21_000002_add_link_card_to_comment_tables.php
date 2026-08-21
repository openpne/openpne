<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lets a comment carry a link card, on the two columns every other body carries one on
 * (2026_08_06_000002_add_link_card_to_body_tables). That migration says comments are deliberately
 * absent because a thread of them stacks cards; talk settled that the other way, drawing the same
 * cards in a denser list than any comment thread.
 *
 * The SQLite index is the same exception 2026_08_07_000001 makes: InnoDB backs a foreign key with an
 * index and SQLite backs it with nothing, so without this the prune sweep's "does any body still
 * point at this card" degrades into a full scan of these tables there.
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
