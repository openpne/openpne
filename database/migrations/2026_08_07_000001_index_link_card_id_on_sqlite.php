<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * InnoDB creates a backing index for every foreign key and SQLite creates none, so this one is
 * added on the SQLite lane only.
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
