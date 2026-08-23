<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The audience of one talk message's broadcast: the group's members, minus the author, banned
 * members, either-direction blocks against the author, the members the message already named (they
 * get the mention instead), and **anyone who muted the room** — the one line that separates this
 * from the board fan-out's audience (App\Features\Group\Queries\GroupNewPostRecipients).
 *
 * Mute is applied in SQL rather than per recipient because a quiet room is the common case on a site
 * that notifies about every message, and walking members only to drop them is the cost this avoids.
 */
class GroupTalkBroadcastRecipients
{
    /**
     * @param  list<int>  $excludeIds  the members the message mentioned, snapshotted at dispatch time
     * @return Builder<Member>
     */
    public function viewers(Group $group, Member $author, array $excludeIds = []): Builder
    {
        $query = Member::query()
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false)
            ->whereIn('id', DB::table('group_members')
                ->where('group_id', $group->getKey())
                ->where('is_talk_muted', false)
                ->select('member_id'));

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        return $query;
    }

    /**
     * Narrow the audience to members holding an explicit opt-in for $kind on either channel — what a
     * site whose default is off broadcasts to. Without it every message would walk the whole
     * membership to decide that nobody wants it.
     *
     * @param  Builder<Member>  $audience
     * @return Builder<Member>
     */
    public function restrictToOptedIn(Builder $audience, NotificationKind $kind): Builder
    {
        return $audience->whereExists(fn (QueryBuilder $rows) => $rows
            ->from('member_notification_settings')
            ->whereColumn('member_notification_settings.member_id', 'members.id')
            ->where('kind', $kind->value)
            ->where('is_enabled', true));
    }
}
