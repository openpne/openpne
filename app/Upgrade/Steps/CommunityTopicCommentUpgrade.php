<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic_comment` (opCommunityTopicPlugin) → OpenPNE 4 `community_topic_comments`.
 *
 * id is preserved because community_topic_comment_image references community_topic_comment.id;
 * keeping it lets the (deferred) comment-image upgrade rewire by id. member_id stays nullable: a
 * withdrawn author is NULL in OpenPNE 3 (onDelete set null) and the comment is kept. number is a racy
 * max+1 on a non-unique index, so legacy duplicate (topic, number) rows import losslessly. body is
 * TEXT → TEXT; timestamps are the original post dates, not the upgrade run's clock.
 *
 * community_topic_comment_image is not migrated here — it is recorded in
 * StepRegistry::deferredSourceTables() (binary migration pending the `file` step).
 */
class CommunityTopicCommentUpgrade extends UpgradeStep
{
    protected string $source = 'community_topic_comment';

    protected string $target = 'community_topic_comments';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'community_topic_id' => Column::source('community_topic_id'),
            'member_id' => Column::source('member_id'),
            'number' => Column::source('number'),
            'body' => Column::source('body'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
