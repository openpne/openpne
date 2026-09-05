<?php

namespace App\Features\GroupTalk;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/**
 * Not a timestamp: a MySQL timestamp is second-precise, so a strict `>` watermark would silently
 * drop every change that shared its second.
 */
final class TalkReactionVersion
{
    /** Read before a page query, never after: a change landing between the two would sit below the watermark the client comes back with. */
    public static function of(Group $group): int
    {
        return (int) DB::table('groups')->where('id', $group->getKey())->value('talk_reaction_seq');
    }

    /**
     * The caller must hold the group row through {@see TalkWriteLock::hold()}, which is what makes
     * the issued version unique and monotonic within the group. Call it only for an actual change: a
     * no-op bump would wake every open tab to re-read a row that reads the same.
     */
    public static function bump(GroupMessage $message): void
    {
        DB::table('groups')->where('id', $message->group_id)->increment('talk_reaction_seq');

        $version = DB::table('groups')->where('id', $message->group_id)->value('talk_reaction_seq');

        DB::table('group_messages')->where('id', $message->getKey())->update(['reactions_version' => $version]);
    }
}
