<?php

namespace App\Features\GroupTalk;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Facades\DB;

/**
 * The counter that tells a reader's open tab which messages have changed under it. The talk poll
 * reads forward from a `(created_at, id)` position, so a reaction on a message already on screen
 * moves nothing it watches; the version is the second watermark that does.
 *
 * `groups.talk_reaction_seq` is the issuer and each touched `group_messages.reactions_version` holds
 * what it was issued. Not a timestamp: a MySQL timestamp is second-precise, so a strict `>`
 * watermark would silently drop every change that shared its second.
 */
final class TalkReactionVersion
{
    /** The group's current high-water mark. Read *before* a page query, never after — see {@see bump()}. */
    public static function of(Group $group): int
    {
        return (int) DB::table('groups')->where('id', $group->getKey())->value('talk_reaction_seq');
    }

    /**
     * Record that $message changed, and give it the next version in its group.
     *
     * Caller must be holding the group row through {@see TalkWriteLock::hold()}, and must only call
     * this when something actually changed: a no-op that bumped would wake every open tab in the
     * group to re-read a row that reads the same.
     *
     * That lock is the serialization point. Under it two concurrent reactions in one group are
     * ordered rather than interleaved, so the value read back is the one this transaction issued —
     * which is what makes a version unique and monotonic within the group, the property the poll's
     * strict `>` depends on and the reason a reader can take "the newest version I have seen" from
     * any row it was handed.
     */
    public static function bump(GroupMessage $message): void
    {
        DB::table('groups')->where('id', $message->group_id)->increment('talk_reaction_seq');

        $version = DB::table('groups')->where('id', $message->group_id)->value('talk_reaction_seq');

        DB::table('group_messages')->where('id', $message->getKey())->update(['reactions_version' => $version]);
    }
}
