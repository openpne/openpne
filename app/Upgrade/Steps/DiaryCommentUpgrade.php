<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `diary_comment` (opDiaryPlugin) → OpenPNE 4 `diary_comments`, ids and timestamps
 * verbatim. member_id stays nullable: OpenPNE 3 sets it NULL when the author withdraws and keeps
 * the comment.
 */
class DiaryCommentUpgrade extends UpgradeStep
{
    protected string $source = 'diary_comment';

    protected string $target = 'diary_comments';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'diary_id' => Column::source('diary_id'),
            'member_id' => Column::source('member_id'),
            'number' => Column::source('number'),
            'body' => Column::source('body'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    /**
     * link_card_id / link_card_synced_at stay at their null default: OpenPNE 3 has no equivalent, and
     * a null link_card_synced_at is the "never examined" state the read path fetches a card for.
     */
    public function targetDefaults(): array
    {
        return ['link_card_id', 'link_card_synced_at'];
    }

    public function gaps(): array
    {
        return [
            'has_images' => 'Denormalized flag for the diary_comment_image table; OpenPNE 4 derives it from the relation, so this step migrates the comment record only.',
            'diary_comment_image' => 'Comment image attachments — migrated by DiaryCommentImageUpgrade (its own join-row step), not this record step.',
            'diary_comment_unread' => 'Per-member unread-comment state — outside this step.',
            'diary_comment_update' => 'Per-member comment read tracking — outside this step.',
        ];
    }
}
