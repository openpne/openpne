<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Backs the diary comment history box (DiaryCommentHistory query), which finds the diaries a member
 * commented on: SELECT diary_id FROM diary_comments WHERE member_id = ?. member_id leads so that query
 * is an index-only scan.
 *
 * InnoDB then adopts (member_id, diary_id) to back the member_id foreign key and drops the auto-created
 * member_id index. down() therefore re-adds a standalone member_id index before dropping the composite,
 * so the foreign key keeps a backing index — otherwise the drop fails with MySQL error 1553. The
 * member_id index is left in place (it restores the original auto-created backing); dropping it too
 * would re-trigger 1553. SQLite has no such adoption and round-trips either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diary_comments', function (Blueprint $table) {
            $table->index(['member_id', 'diary_id']);
        });
    }

    public function down(): void
    {
        Schema::table('diary_comments', function (Blueprint $table) {
            $table->index('member_id');
            $table->dropIndex(['member_id', 'diary_id']);
        });
    }
};
