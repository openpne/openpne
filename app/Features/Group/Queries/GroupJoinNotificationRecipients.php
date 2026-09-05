<?php

namespace App\Features\Group\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;

/**
 * Empty when the group opted out of join notifications (OpenPNE 3 per-community
 * is_send_pc_joinCommunity_mail). A blocked pair chose mutual invisibility, so the join stays
 * hidden from an admin who still governs the group through the member list.
 */
class GroupJoinNotificationRecipients
{
    /** @return list<Member> */
    public function __invoke(Group $group, Member $joiner): array
    {
        if (! $group->is_join_notification_enabled) {
            return [];
        }

        $adminIds = GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('role', GroupRole::Admin->value)
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
