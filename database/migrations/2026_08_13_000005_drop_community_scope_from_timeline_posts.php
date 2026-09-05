<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Rows before column: dropping the column first would make every community post an ordinary one and
 * publish a group's conversation SNS-wide between the two statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chunked so a large fixture set does not build one enormous transaction; the FK cascade
        // does the dependent tables.
        do {
            $deleted = DB::table('timeline_posts')
                ->whereNotNull('community_id')
                ->limit(1000)
                ->delete();
        } while ($deleted > 0);

        // The posts' image Files and their bytes are left behind: a migration does not delete disk objects.

        // Matched on the stored FQCN because the notification class is deleted in this change.
        DB::table('notifications')
            ->where('type', 'App\Notifications\Timeline\TimelineCommunityPostedNotification')
            ->delete();

        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn('community_id');
        });
    }

    public function down(): void
    {
        // The column comes back empty: no down() can undo the delete up() made.
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }
};
