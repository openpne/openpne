<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=1, pending) → OpenPNE 4 `community_join_requests`.
 *
 * The other half of the is_pre split (CommunityMemberUpgrade takes is_pre=0). A pending applicant
 * carries only the join request — community_id, member_id, created_at — with no role or mail flags;
 * those source columns are consumed/gapped by the confirmed-member step reading the same table.
 */
class CommunityJoinRequestUpgrade extends UpgradeStep
{
    protected string $source = 'community_member';

    protected string $target = 'community_join_requests';

    public function columns(): array
    {
        return [
            'community_id' => Column::source('community_id'),
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
}
