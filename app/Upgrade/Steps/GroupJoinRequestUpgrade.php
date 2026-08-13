<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=1, pending) → OpenPNE 4 `group_join_requests`.
 *
 * The other half of the is_pre split (GroupMemberUpgrade takes is_pre=0). A pending applicant
 * carries only the join request — community_id, member_id, created_at — with no role or mail flags;
 * those source columns are consumed/gapped by the confirmed-member step reading the same table.
 */
class GroupJoinRequestUpgrade extends UpgradeStep
{
    protected string $source = 'community_member';

    protected string $target = 'group_join_requests';

    public function columns(): array
    {
        return [
            'group_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'created_at' => Column::source('created_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'is_pre = 1';
    }

    public function filterColumns(): array
    {
        return ['is_pre'];
    }

    public function memberRefs(): array
    {
        return ['member_id'];
    }
}
