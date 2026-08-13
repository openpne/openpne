<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Indexes the link-card foreign key on SQLite, which MySQL already has.
 *
 * InnoDB creates a backing index for every foreign key; SQLite creates none. So the same schema is
 * indexed on one engine and not the other, and the prune sweep — which asks "does any body still
 * point at this card?" — degrades into a full scan of all four body tables per candidate card there.
 * Every card that really is unreferenced reads all four to the end, which is the case the command
 * exists to handle.
 *
 * Added only on SQLite because adding it on MySQL would create a second index over the same column,
 * paying write amplification on four hot tables for nothing. That leaves the two schemas differing
 * by an index name, which is fine: each engine is migrated from scratch, including by
 * `openpne:copy-database` when a site moves between them.
 */
return new class extends Migration
{
    private const TABLES = ['diaries', 'group_topics', 'community_events', 'timeline_posts'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->index('link_card_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropIndex(['link_card_id']);
            });
        }
    }
};
