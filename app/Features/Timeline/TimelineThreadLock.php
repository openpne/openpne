<?php

namespace App\Features\Timeline;

use App\Models\TimelinePost;
use Illuminate\Support\Facades\DB;

/**
 * Every write that touches a thread's reactions takes this first: the root row exclusively, then
 * the reply when the target is one (docs/internals/timeline.md, "Reactions"). Both are locking
 * reads, so under REPEATABLE READ they see the latest committed row rather than the snapshot.
 */
final class TimelineThreadLock
{
    /** Call inside a transaction: the lock is held until it commits. */
    public static function hold(TimelinePost $post): bool
    {
        $rootId = $post->in_reply_to_id ?? $post->getKey();

        $root = DB::table('timeline_posts')
            ->where('id', $rootId)
            ->whereNull('in_reply_to_id')
            ->lockForUpdate()
            ->value('id');

        if ($root === null) {
            return false;
        }

        if ($post->in_reply_to_id === null) {
            return true;
        }

        $live = DB::table('timeline_posts')
            ->where('id', $post->getKey())
            ->where('in_reply_to_id', $rootId)
            ->lockForUpdate()
            ->value('id');

        return $live !== null;
    }
}
