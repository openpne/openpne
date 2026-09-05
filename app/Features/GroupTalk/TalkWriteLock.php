<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/**
 * Every application write that touches a reaction takes this first: the group's row exclusively,
 * then the message (docs/internals/group-talk.md, "One lock order"). Both are locking reads, so
 * under REPEATABLE READ they see the latest committed row rather than the transaction's snapshot.
 */
final class TalkWriteLock
{
    /**
     * Call inside a transaction: the lock is held until it commits, which is what makes the answer
     * keep holding.
     */
    public static function hold(GroupMessage $message): bool
    {
        $group = DB::table('groups')->where('id', $message->group_id)->lockForUpdate()->value('id');

        if ($group === null) {
            return false;
        }

        $live = DB::table('group_messages')
            ->where('id', $message->getKey())
            ->where('group_id', $message->group_id)
            ->lockForUpdate()
            ->value('id');

        return $live !== null;
    }
}
