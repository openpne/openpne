<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_relationship` (is_access_block) → OpenPNE 4 `member_blocks`, one directed row per
 * block (member_id_from = blocker).
 */
class MemberBlockUpgrade extends UpgradeStep
{
    protected string $source = 'member_relationship';

    protected string $target = 'member_blocks';

    public function columns(): array
    {
        return [
            'blocker_id' => Column::source('member_id_from'),
            'blocked_id' => Column::source('member_id_to'),
            'created_at' => Column::source('created_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'is_access_block = 1';
    }

    public function filterColumns(): array
    {
        return ['is_access_block'];
    }

    public function memberRefs(): array
    {
        return ['member_id_from', 'member_id_to'];
    }
}
