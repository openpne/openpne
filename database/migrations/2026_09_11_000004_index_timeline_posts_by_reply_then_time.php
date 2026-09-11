<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every reader filters on the reply flag, so it joins both axes; alone, its SQLite foreign-key index
 * won the feeds' IS NULL and sorted the whole table (docs/internals/ordering.md, "SQLite foreign-key indexes").
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every step checks the schema first, so an interrupted run resumes where it stopped.
        foreach ([['in_reply_to_id', 'created_at', 'id'], ['member_id', 'in_reply_to_id', 'created_at']] as $columns) {
            if (! Schema::hasIndex('timeline_posts', $columns)) {
                Schema::table('timeline_posts', fn (Blueprint $table) => $table->index($columns));
            }
        }
        // By columns, not name: SQLite holds the index the foreign-key migration added, MySQL keeps
        // InnoDB's own until another index backs the key, and both are dead once the composite does.
        foreach (Schema::getIndexes('timeline_posts') as $index) {
            if (in_array($index['columns'], [['in_reply_to_id'], ['created_at', 'id'], ['member_id', 'created_at']], true)) {
                Schema::table('timeline_posts', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }
    }

    public function down(): void
    {
        // Named as InnoDB names its own, so the foreign-key migration's down() leaves it alone and the key stays backed.
        foreach ([['created_at', 'id'], ['member_id', 'created_at'], ['in_reply_to_id']] as $columns) {
            if (! Schema::hasIndex('timeline_posts', $columns)) {
                Schema::table('timeline_posts', fn (Blueprint $table) => $columns === ['in_reply_to_id']
                    ? $table->index($columns, 'timeline_posts_in_reply_to_id_foreign')
                    : $table->index($columns));
            }
        }
        foreach ([['in_reply_to_id', 'created_at', 'id'], ['member_id', 'in_reply_to_id', 'created_at']] as $columns) {
            if (Schema::hasIndex('timeline_posts', $columns)) {
                Schema::table('timeline_posts', fn (Blueprint $table) => $table->dropIndex($columns));
            }
        }
    }
};
