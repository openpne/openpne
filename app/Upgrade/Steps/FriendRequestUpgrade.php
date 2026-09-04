<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_relationship` (is_friend_pre) → OpenPNE 4 `friend_requests`, one directed row per
 * pending request. OpenPNE 3 sets is_friend_pre only on the requester's row and clears it on accept,
 * so is_friend_pre and is_friend never overlap.
 */
class FriendRequestUpgrade extends UpgradeStep
{
    protected string $source = 'member_relationship';

    protected string $target = 'friend_requests';

    public function columns(): array
    {
        return [
            'requester_id' => Column::source('member_id_from'),
            'target_id' => Column::source('member_id_to'),
            'created_at' => Column::source('created_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'is_friend_pre = 1';
    }

    public function filterColumns(): array
    {
        return ['is_friend_pre'];
    }

    public function memberRefs(): array
    {
        return ['member_id_from', 'member_id_to'];
    }
}
