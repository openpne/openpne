<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `activity_data` thread starters outside any community → OpenPNE 4 `timeline_posts`, ids
 * and timestamps verbatim. Replies follow in TimelineReplyUpgrade once these rows exist for the
 * self-FK.
 */
class TimelinePostUpgrade extends UpgradeStep
{
    protected string $source = 'activity_data';

    protected string $target = 'timeline_posts';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'in_reply_to_id' => Column::expr('NULL'),
            'body' => Column::source('body'),
            'visibility' => Column::expr(ActivityThread::visibilityCase('`activity_data`.`public_flag`'), uses: ['public_flag']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return ActivityThread::startsThread('activity_data').' AND `activity_data`.`foreign_table` IS NULL';
    }

    public function filterColumns(): array
    {
        return ['in_reply_to_activity_id', 'id', 'foreign_table'];
    }

    public function targetFilter(): ?string
    {
        return '`in_reply_to_id` IS NULL';
    }

    /** A null link_card_synced_at is the "never examined" state the read path fetches a card for. */
    public function targetDefaults(): array
    {
        return ['link_card_id', 'link_card_synced_at'];
    }

    public function gaps(): array
    {
        return ActivityThread::recordGaps() + [
            'foreign_id' => "Only meaningful with foreign_table = 'community': the talk landing's group id.",
        ];
    }
}
