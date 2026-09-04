<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic` (opCommunityTopicPlugin) → OpenPNE 4 `group_topics`, ids and
 * timestamps verbatim. member_id stays nullable (OpenPNE 3 sets it NULL when the author withdraws);
 * updated_at is the board sort key, and topic_updated_at is OpenPNE 3's latest-topics activity
 * timestamp.
 */
class GroupTopicUpgrade extends UpgradeStep
{
    protected string $source = 'community_topic';

    protected string $target = 'group_topics';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'name' => Column::source('name'),
            'body' => Column::source('body'),
            'topic_updated_at' => Column::source('topic_updated_at'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    /**
     * format stays at its plain default: OpenPNE 3 community topics carry no rich-text decoration.
     * link_card_id / link_card_synced_at stay null: OpenPNE 3 has no equivalent, and a null
     * link_card_synced_at is the "never examined" state the read path fetches a card for.
     */
    public function targetDefaults(): array
    {
        return ['format', 'link_card_id', 'link_card_synced_at'];
    }
}
