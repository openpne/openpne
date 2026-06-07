<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic` (opCommunityTopicPlugin) → OpenPNE 4 `community_topics`.
 *
 * id is preserved because community_topic_comment and community_topic_image reference
 * community_topic.id; keeping it lets the (deferred) comment / image upgrades rewire by id.
 * member_id stays nullable: a withdrawn author is NULL in OpenPNE 3 (onDelete set null) and the
 * topic is kept. name/body are TEXT → TEXT, so long content round-trips untruncated. timestamps and
 * topic_updated_at are the original dates, not the upgrade run's clock — updated_at is the board sort
 * key and topic_updated_at feeds the (unported) latest-topics widget, both carried for fidelity.
 *
 * community_topic_image is not migrated here — it is recorded in StepRegistry::deferredSourceTables()
 * (binary migration pending the `file` step, like the other image tables).
 */
class CommunityTopicUpgrade extends UpgradeStep
{
    protected string $source = 'community_topic';

    protected string $target = 'community_topics';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'community_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'name' => Column::source('name'),
            'body' => Column::source('body'),
            'topic_updated_at' => Column::source('topic_updated_at'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
