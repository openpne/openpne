<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The community timeline is replaced by group talk (docs/internals/group-talk.md), so its rows and
 * its column both go.
 *
 * **Rows before column, and the order is load-bearing.** A community post is kept out of every
 * SNS-wide feed by `community_id IS NOT NULL`; dropping the column first would make each of them an
 * ordinary post and publish a group's conversation to the whole site between one statement and the
 * next.
 *
 * This DESTROYS DATA, deliberately and only because it can: there are no production OpenPNE 4
 * installs yet, so the only community timeline rows anywhere are dev and demo fixtures. Nothing is
 * migrated into talk. What goes:
 *
 *  - every community-scoped `timeline_posts` row, replies included (a reply inherits its parent's
 *    community_id, so the one predicate catches the whole thread);
 *  - their `timeline_post_images` / `_mentions` / `_tags` rows, by FK cascade;
 *  - their image **File rows and bytes are NOT reclaimed** — a migration has no business deleting
 *    disk objects, and a pre-release fixture leak costs nothing. No housekeeping command collects
 *    orphaned Files today; only a fresh install (or deleting them by hand) settles it;
 *  - `timeline_posted_community` notification rows, which point at posts that no longer exist;
 *  - any unread/read state derived from them.
 *
 * After a production release this would have to be a transfer instead, with an explicit
 * disposition for every row class; it is a delete only because that release has not happened.
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

        // The rows that announced them: their target is gone, so the feed row would resolve to
        // nothing. Matched on the stored notification class — the FQCN is what `notifications.type`
        // holds, and the class itself is deleted in this change.
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
        // The column comes back empty. The rows it scoped are gone for good — that is what up()
        // decided, and no down() can undo a delete.
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }
};
