<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * InnoDB backs every foreign key with an index and SQLite backs none, so this one is added on the
 * SQLite lane only; the schemas differ by an index, acceptable since each engine,
 * `openpne:copy-database` included, is migrated from scratch.
 */
return new class extends Migration
{
    private const TABLES = ['diaries', 'group_topics', 'group_events', 'timeline_posts'];

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
