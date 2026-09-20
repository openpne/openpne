<?php

namespace App\Features\Group;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Support\Facades\DB;

/**
 * Every write that touches a board's reactions takes this first, inside a transaction: the topic or
 * event row exclusively, then the comment re-read under it when the target is one (docs/internals/group-boards.md, "Reactions").
 * Both are locking reads, so under REPEATABLE READ they see the latest committed row rather than the snapshot.
 */
final class BoardLock
{
    public static function hold(GroupTopic|GroupEvent|GroupTopicComment|GroupEventComment $target): bool
    {
        if ($target instanceof GroupTopic || $target instanceof GroupEvent) {
            return DB::table($target->getTable())->where('id', $target->getKey())->lockForUpdate()->value('id') !== null;
        }

        [$parents, $column] = $target instanceof GroupTopicComment
            ? ['group_topics', 'group_topic_id']
            : ['group_events', 'group_event_id'];
        $parentId = (int) $target->{$column};

        $parent = DB::table($parents)->where('id', $parentId)->lockForUpdate()->value('id');

        if ($parent === null) {
            return false;
        }

        $live = DB::table($target->getTable())->where('id', $target->getKey())->where($column, $parentId)->lockForUpdate()->value('id');

        return $live !== null;
    }
}
