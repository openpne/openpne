<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `diary_comment` (opDiaryPlugin) → OpenPNE 4 `diary_comments`.
 *
 * id is preserved because diary_comment_image references diary_comment.id; keeping it
 * lets the (deferred) comment-image upgrade rewire by id. timestamps are the original
 * post dates, not the upgrade run's clock. member_id stays nullable: a withdrawn author
 * is NULL in OpenPNE 3 (onDelete set null) and the comment is kept. body is TEXT → TEXT,
 * so long content round-trips untruncated.
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

    public function gaps(): array
    {
        return [
            'has_images' => 'Denormalized flag for the diary_comment_image table; this step migrates the comment record only.',
            'diary_comment_image' => 'Comment image attachments — outside this step (image delivery is not built).',
            'diary_comment_unread' => 'Per-member unread-comment state — outside this step.',
            'diary_comment_update' => 'Per-member comment read tracking — outside this step.',
        ];
    }
}
