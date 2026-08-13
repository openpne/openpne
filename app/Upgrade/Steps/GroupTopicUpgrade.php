<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_topic` (opCommunityTopicPlugin) → OpenPNE 4 `group_topics`.
 *
 * id is preserved because community_topic_comment and community_topic_image reference
 * community_topic.id; keeping it lets the comment / image upgrades rewire by id.
 * member_id stays nullable: a withdrawn author is NULL in OpenPNE 3 (onDelete set null) and the
 * topic is kept. name/body are TEXT → TEXT, so long content round-trips untruncated. timestamps and
 * topic_updated_at are the original dates, not the upgrade run's clock — updated_at is the board sort
 * key; topic_updated_at is the OpenPNE 3 latest-topics activity timestamp, carried for fidelity.
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

    /** OpenPNE 3 community topics carry no rich-text decoration; the body stays plain (schema default). */
    /**
     * `link_card_id` / `link_card_synced_at` are left at their schema default (null) rather than
     * mapped: OpenPNE 3 has no equivalent, and a null `link_card_synced_at` is exactly the "never
     * examined" state the read path looks for — so migrated records pick up cards on first view, if
     * the operator has the feature on at all.
     */
    public function targetDefaults(): array
    {
        return ['format', 'link_card_id', 'link_card_synced_at'];
    }
}
