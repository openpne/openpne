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
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['in_reply_to_id', 'created_at', 'id']);
            $table->index(['member_id', 'in_reply_to_id', 'created_at']);
        });
        // By columns, not name: SQLite holds the index the foreign-key migration added, MySQL keeps
        // InnoDB's own until another index backs the key, and both are dead once the composite does.
        foreach (Schema::getIndexes('timeline_posts') as $index) {
            if ($index['columns'] === ['in_reply_to_id']) {
                Schema::table('timeline_posts', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            }
        }
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
            $table->dropIndex(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
            $table->index(['member_id', 'created_at']);
            // Named as InnoDB names its own, so the foreign-key migration's down() leaves it alone and the key stays backed.
            $table->index('in_reply_to_id', 'timeline_posts_in_reply_to_id_foreign');
        });
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['in_reply_to_id', 'created_at', 'id']);
            $table->dropIndex(['member_id', 'in_reply_to_id', 'created_at']);
        });
    }
};
