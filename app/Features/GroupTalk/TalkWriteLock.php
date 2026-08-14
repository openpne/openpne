<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/**
 * The one lock order a talk write takes when it touches more than the row it names: the group's row
 * first, exclusively, then the message.
 *
 * A gate is answered before the write runs, and `reactions.reactable_id` carries no foreign key —
 * so nothing at the engine level would refuse a reaction onto a message, or a group, that a
 * concurrent request deleted in between. On a polymorphic column that leaves a row nothing would
 * ever collect. Every path that takes a message away ({@see Actions\DeleteGroupMessage::purge()},
 * App\Features\Group\Actions\DeleteGroup::purge()) holds this same row first and in this same
 * order, so two of them queue rather than interleave and neither deadlocks the other.
 *
 * Both reads are locking reads: under REPEATABLE READ those see the latest committed row rather
 * than the transaction's snapshot, which is the whole point of re-reading.
 */
final class TalkWriteLock
{
    /**
     * Take the lock, and answer whether $message is still a live row of its group. Call inside a
     * transaction — the lock is held until it commits, which is what makes the answer keep holding.
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
