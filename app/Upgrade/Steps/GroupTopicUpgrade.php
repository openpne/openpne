<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic` (opCommunityTopicPlugin) → OpenPNE 4 `group_topics`, ids and timestamps
 * verbatim; member_id stays nullable (OpenPNE 3 sets it NULL when the author withdraws). bumped_at
 * starts at created_at and BoardBumpBackfill settles it from the comments once they have landed.
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
            'bumped_at' => Column::source('created_at'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function gaps(): array
    {
        return [
            'topic_updated_at' => 'OpenPNE 3 bumped it on a comment and on a name/body edit alike, so it maps to neither bumped_at nor edited_at; the board order it fed is rebuilt from the comments.',
        ];
    }

    /**
     * format stays at its plain default and edited_at null: OpenPNE 3 community topics carry no
     * rich-text decoration and cannot tell an edit from a comment. link_card_id / link_card_synced_at
     * stay null: OpenPNE 3 has no equivalent, and a null link_card_synced_at is the "never examined"
     * state the read path fetches a card for.
     */
    public function targetDefaults(): array
    {
        return ['format', 'link_card_id', 'link_card_synced_at', 'edited_at'];
    }
}
