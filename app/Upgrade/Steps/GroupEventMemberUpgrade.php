<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event_member` (opCommunityTopicPlugin RSVP pivot) → OpenPNE 4
 * `group_event_members`. Row presence is the whole signal (no status column), and member_id is NOT
 * NULL because both OpenPNE 3 FKs cascade.
 */
class GroupEventMemberUpgrade extends UpgradeStep
{
    protected string $source = 'community_event_member';

    protected string $target = 'group_event_members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_event_id' => Column::source('community_event_id'),
            'member_id' => Column::source('member_id'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
