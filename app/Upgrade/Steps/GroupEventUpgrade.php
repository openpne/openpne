<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event` (opCommunityTopicPlugin) → OpenPNE 4 `group_events`, ids, scheduling
 * data and timestamps verbatim. member_id stays nullable (OpenPNE 3 sets it NULL when the author
 * withdraws); updated_at is the board sort key, and event_updated_at is OpenPNE 3's latest-events
 * activity timestamp.
 */
class GroupEventUpgrade extends UpgradeStep
{
    protected string $source = 'community_event';

    protected string $target = 'group_events';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'name' => Column::source('name'),
            'body' => Column::source('body'),
            'event_updated_at' => Column::source('event_updated_at'),
            'open_date' => Column::source('open_date'),
            'open_date_comment' => Column::source('open_date_comment'),
            'area' => Column::source('area'),
            'application_deadline' => Column::source('application_deadline'),
            'capacity' => Column::source('capacity'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    /**
     * format stays at its plain default: OpenPNE 3 community events carry no rich-text decoration.
     * link_card_id / link_card_synced_at stay null: OpenPNE 3 has no equivalent, and a null
     * link_card_synced_at is the "never examined" state the read path fetches a card for.
     */
    public function targetDefaults(): array
    {
        return ['format', 'link_card_id', 'link_card_synced_at'];
    }
}
