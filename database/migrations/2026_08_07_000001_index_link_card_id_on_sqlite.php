<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * InnoDB backs every foreign key with an index and SQLite backs none, so this one is added on the
 * SQLite lane only (the general, introspecting form is 2026_09_11_000003_index_foreign_key_columns_lacking_one).
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
