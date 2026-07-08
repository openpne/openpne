<?php

namespace App\Features\Community\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;

/**
 * Who a new community join notifies: the community's admins, never the joiner. Empty when the community
 * opted out of join notifications (OpenPNE 3 per-community is_send_pc_joinCommunity_mail).
 *
 * Each recipient must currently be able to receive it: not banned (is_login_rejected, as elsewhere in
 * the notification catalog) and no block in either direction against the joiner — a blocked pair chose
 * mutual invisibility, so the join stays hidden even though the admin still governs the community
 * through the member list.
 */
class CommunityJoinNotificationRecipients
{
    /** @return list<Member> */
    public function __invoke(Community $community, Member $joiner): array
    {
        if (! $community->is_join_notification_enabled) {
            return [];
        }

        $adminIds = CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('role', CommunityRole::Admin->value)
            ->where('member_id', '!=', $joiner->getKey())
            ->pluck('member_id');

        $recipients = [];

        foreach (Member::query()->findMany($adminIds) as $admin) {
            if (! $admin->is_login_rejected && ! BlockLookup::hasAnyBlockBetween($admin, $joiner)) {
                $recipients[] = $admin;
            }
        }

        return $recipients;
    }
}
