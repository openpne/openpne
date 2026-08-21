<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event_comment` (opCommunityTopicPlugin) → OpenPNE 4 `group_event_comments`.
 *
 * id is preserved because community_event_comment_image references community_event_comment.id; keeping
 * it lets the comment-image upgrade rewire by id. member_id stays nullable: a withdrawn
 * author is NULL in OpenPNE 3 (onDelete set null) and the comment is kept. number is a racy max+1 on a
 * non-unique index, so legacy duplicate (event, number) rows import losslessly. body is TEXT → TEXT;
 * timestamps are the original post dates, not the upgrade run's clock.
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
     * `link_card_id` / `link_card_synced_at` are left at their schema default (null) rather than
     * mapped, as every other body's step leaves them: OpenPNE 3 has no equivalent, and a null
     * `link_card_synced_at` is exactly the "never examined" state the read path looks for — so a
     * migrated comment picks up a card the first time its page is opened, if the operator has the
     * feature on at all.
     */
    public function targetDefaults(): array
    {
        return ['link_card_id', 'link_card_synced_at'];
    }
}
