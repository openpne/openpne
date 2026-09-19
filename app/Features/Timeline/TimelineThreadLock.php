<?php

namespace App\Features\Timeline;

use App\Models\TimelinePost;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every write that touches a thread's reactions takes this first: the root row exclusively, then
 * the reply when the target is one (docs/internals/timeline.md, "Reactions"). Both are locking
 * reads, so under REPEATABLE READ they see the latest committed row rather than the snapshot.
 */
final class TimelineThreadLock
{
    /**
     * Call inside a transaction: the lock is held until it commits. A shared hold still excludes
     * the reaction writers, which take the rows exclusively, without waiting on another member's
     * in-flight reply, which holds the root shared through its foreign key.
     */
    public static function hold(TimelinePost $post, bool $shared = false): bool
    {
        $rootId = $post->in_reply_to_id ?? $post->getKey();

        $root = self::lock(DB::table('timeline_posts')->where('id', $rootId)->whereNull('in_reply_to_id'), $shared)->value('id');

        if ($root === null) {
            return false;
        }

        if ($post->in_reply_to_id === null) {
            return true;
        }

        $live = self::lock(DB::table('timeline_posts')->where('id', $post->getKey())->where('in_reply_to_id', $rootId), $shared)->value('id');

        return $live !== null;
    }

    private static function lock(Builder $query, bool $shared): Builder
    {
        return $shared ? $query->sharedLock() : $query->lockForUpdate();
    }
}
