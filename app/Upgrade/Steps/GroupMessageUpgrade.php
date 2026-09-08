<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `activity_data` threads rooted in a community → OpenPNE 4 `group_messages`, starters and
 * replies in one statement: `in_reply_to_id` has carried no FK since the `2026_08_18_000001`
 * migration dropped it, so no order between a root and its replies is needed. A reply attaches to
 * the root, and the root's community is the group (docs/internals/upgrade.md, "Activity threads").
 */
class GroupMessageUpgrade extends UpgradeStep
{
    protected string $source = 'activity_data';

    protected string $target = 'group_messages';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'group_id' => Column::expr(ActivityThread::rootColumn('activity_data', 'foreign_id'), uses: ['in_reply_to_activity_id', 'id', 'foreign_id']),
            'member_id' => Column::source('member_id'),
            'in_reply_to_id' => Column::expr(
                'CASE WHEN '.ActivityThread::startsThread('activity_data').' THEN NULL ELSE '.ActivityThread::rootId('activity_data').' END',
                uses: ['in_reply_to_activity_id', 'id'],
            ),
            'body' => Column::source('body'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return ActivityThread::landsInGroup('activity_data');
    }

    public function filterColumns(): array
    {
        return ['in_reply_to_activity_id', 'id'];
    }

    /** A null link_card_synced_at is the "never examined" state the read path fetches a card for. */
    public function targetDefaults(): array
    {
        return ['reactions_version', 'link_card_id', 'link_card_synced_at'];
    }

    public function nullGuards(): array
    {
        return [
            'group_id' => "The root row's foreign_id: the filter's EXISTS requires that root and a community it points at (ActivityThread::groupRoot), and in_reply_to_activity_id only locates the root.",
        ];
    }

    public function gaps(): array
    {
        return ActivityThread::recordGaps() + [
            'public_flag' => 'Talk has no per-message audience: a thread lands only when its root was for every member (ActivityThread::groupRoot), and the flag itself is not carried.',
            'foreign_table' => "The root's scope decides the landing (it is 'community' here by the filter); a reply's own is ignored (ActivityPreflight counts the cross-scope ones).",
        ];
    }
}
