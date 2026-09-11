<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every feed reads top-level posts, so the reply flag leads the time axis; the single-column index
 * SQLite got for the foreign key won its IS NULL over the axis and sorted the whole table
 * (docs/internals/ordering.md, "SQLite foreign-key indexes").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['in_reply_to_id', 'created_at', 'id']);
        });
        // By columns, not name: SQLite holds the index the foreign-key migration added, MySQL keeps
        // InnoDB's own only when it was created explicitly, and both are dead once the composite backs the key.
        foreach (Schema::getIndexes('timeline_posts') as $index) {
            if ($index['columns'] === ['in_reply_to_id']) {
                Schema::table('timeline_posts', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
            $table->index('in_reply_to_id');
        });
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['in_reply_to_id', 'created_at', 'id']);
        });
    }
};
