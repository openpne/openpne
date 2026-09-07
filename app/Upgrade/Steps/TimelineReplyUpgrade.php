<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `activity_data` replies whose thread starts outside any community → OpenPNE 4
 * `timeline_posts`, attached to the thread root with the root's audience: the OpenPNE 4 thread is
 * flat and gated as one (docs/internals/timeline.md), and OpenPNE 3 drew a reply under its root's
 * flag too.
 */
class TimelineReplyUpgrade extends UpgradeStep
{
    protected string $source = 'activity_data';

    protected string $target = 'timeline_posts';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'in_reply_to_id' => Column::expr(ActivityThread::rootId('activity_data'), uses: ['in_reply_to_activity_id', 'id']),
            'body' => Column::source('body'),
            'visibility' => Column::expr(ActivityThread::visibilityCase(ActivityThread::rootColumn('activity_data', 'public_flag')), uses: ['in_reply_to_activity_id', 'id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return '`activity_data`.`in_reply_to_activity_id` IS NOT NULL AND '.ActivityThread::parentExists('activity_data')
            .' AND '.ActivityThread::landsOnTimeline('activity_data');
    }

    public function filterColumns(): array
    {
        return ['in_reply_to_activity_id', 'id'];
    }

    public function targetFilter(): ?string
    {
        return '`in_reply_to_id` IS NOT NULL';
    }

    public function targetDefaults(): array
    {
        return ['link_card_id', 'link_card_synced_at'];
    }

    public function gaps(): array
    {
        return ActivityThread::recordGaps() + [
            'public_flag' => "A reply's own flag is ignored: the thread shares the root's visibility (the root's flag is read instead).",
            'foreign_table' => "A reply's own scope is ignored: the root decides the landing (ActivityPreflight counts the cross-scope ones).",
            'foreign_id' => "A reply's own scope is ignored: the root decides the landing.",
        ];
    }
}
