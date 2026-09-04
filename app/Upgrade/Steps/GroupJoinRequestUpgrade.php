<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_member` (is_pre=1, pending) → OpenPNE 4 `group_join_requests`, the other half
 * of the is_pre split with GroupMemberUpgrade. The role and mail-flag source columns are consumed or
 * gapped by that step, which reads the same table.
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
