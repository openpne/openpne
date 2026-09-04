<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event_comment` (opCommunityTopicPlugin) → OpenPNE 4 `group_event_comments`,
 * ids and timestamps verbatim. member_id stays nullable (OpenPNE 3 sets it NULL when the author
 * withdraws), and `number` is a racy max+1 on a non-unique index, so duplicate (event, number) rows
 * import losslessly.
 */
class GroupEventCommentUpgrade extends UpgradeStep
{
    protected string $source = 'community_event_comment';

    protected string $target = 'group_event_comments';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_event_id' => Column::source('community_event_id'),
            'member_id' => Column::source('member_id'),
            'number' => Column::source('number'),
            'body' => Column::source('body'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    /**
     * link_card_id / link_card_synced_at stay at their null default: OpenPNE 3 has no equivalent, and
     * a null link_card_synced_at is the "never examined" state the read path fetches a card for.
     */
    public function targetDefaults(): array
    {
        return ['link_card_id', 'link_card_synced_at'];
    }
}
