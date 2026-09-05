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
 * Mute is filtered in SQL as well as at delivery, so a quiet room is not walked member by member on a
 * site that notifies about every message.
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
     * A row on either channel counts as an opt-in — what a site whose default is off broadcasts to,
     * instead of walking the whole membership to decide that nobody wants it.
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
