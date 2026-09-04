<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic_comment` (opCommunityTopicPlugin) → OpenPNE 4 `group_topic_comments`,
 * ids and timestamps verbatim. member_id stays nullable (OpenPNE 3 sets it NULL when the author
 * withdraws), and `number` is a racy max+1 on a non-unique index, so duplicate (topic, number) rows
 * import losslessly.
 */
class GroupTopicCommentUpgrade extends UpgradeStep
{
    protected string $source = 'community_topic_comment';

    protected string $target = 'group_topic_comments';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_topic_id' => Column::source('community_topic_id'),
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
