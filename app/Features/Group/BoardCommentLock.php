<?php

namespace App\Features\Group;

use App\Models\GroupEventComment;
use App\Models\GroupTopicComment;
use Illuminate\Support\Facades\DB;

/**
 * Every write that touches a board comment's reactions takes this first, inside a transaction: the
 * topic or event row exclusively, then the comment re-read under it (docs/internals/group-boards.md, "Reactions").
 * Both are locking reads, so under REPEATABLE READ they see the latest committed row rather than the snapshot.
 */
final class BoardCommentLock
{
    public static function hold(GroupTopicComment|GroupEventComment $comment): bool
    {
        [$parents, $column] = $comment instanceof GroupTopicComment
            ? ['group_topics', 'group_topic_id']
            : ['group_events', 'group_event_id'];
        $parentId = (int) $comment->{$column};

        $parent = DB::table($parents)->where('id', $parentId)->lockForUpdate()->value('id');

        if ($parent === null) {
            return false;
        }

        $live = DB::table($comment->getTable())->where('id', $comment->getKey())->where($column, $parentId)->lockForUpdate()->value('id');

        return $live !== null;
    }
}
