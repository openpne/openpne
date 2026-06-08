<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event_member` (opCommunityTopicPlugin RSVP pivot) → OpenPNE 4
 * `community_event_members`.
 *
 * Row presence is the whole signal — a row means the member is attending; there is no status column.
 * Both FKs cascade in OpenPNE 3, so deleting the event or the member removes the RSVP; member_id is
 * NOT NULL here (unlike events/comments, which keep withdrawn-author rows). timestamps are the original
 * join dates, not the upgrade run's clock.
 */
class CommunityEventMemberUpgrade extends UpgradeStep
{
    protected string $source = 'community_event_member';

    protected string $target = 'community_event_members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'community_event_id' => Column::source('community_event_id'),
            'member_id' => Column::source('member_id'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
