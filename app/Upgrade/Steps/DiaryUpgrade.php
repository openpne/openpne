<?php

namespace App\Upgrade\Steps;

use App\Features\Diary\Visibility;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `diary` (opDiaryPlugin) → OpenPNE 4 `diaries`.
 *
 * id is preserved because other OpenPNE 3 tables reference diary.id; timestamps are
 * preserved because they are the original post dates, not the upgrade run's clock.
 * title/body are TEXT → TEXT, so long content round-trips untruncated.
 */
class DiaryUpgrade extends UpgradeStep
{
    protected string $source = 'diary';

    protected string $target = 'diaries';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'title' => Column::source('title'),
            'body' => Column::source('body'),
            'visibility' => Column::expr($this->visibilityCase(), uses: ['public_flag', 'is_open']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function gaps(): array
    {
        return [
            'has_images' => 'Denormalized flag for the diary image tables; this step migrates the diary record only.',
            'diary_comment' => 'Diary comments — a separate table, outside this step.',
            'diary_comment_image' => 'Comment image attachments — outside this step.',
            'diary_image' => 'Diary image attachments — outside this step (image delivery is not built).',
            'diary_comment_unread' => 'Per-member comment unread state — outside this step.',
            'diary_comment_update' => 'Comment update tracking — outside this step.',
        ];
    }

    /**
     * public_flag + is_open → Visibility. Every branch's value comes from the runtime
     * enum, so this CASE cannot drift from it. OpenPNE 3 normalises web-public as
     * public_flag=1 + is_open=1 (older data may carry the legacy PUBLIC_FLAG_OPEN=4);
     * is_open on friend/private rows is anomalous, so the restrictive flag wins. An
     * unrecognised flag yields NULL, which the NOT NULL column rejects — the upgrade
     * fails loudly rather than storing an out-of-range visibility.
     */
    private function visibilityCase(): string
    {
        return sprintf(
            'CASE'
            .' WHEN public_flag = 1 AND is_open = 1 THEN %1$d' // Open (web-public normalized form)
            .' WHEN public_flag = 4 THEN %1$d'                 // Open (legacy PUBLIC_FLAG_OPEN)
            .' WHEN public_flag = 1 THEN %2$d'                 // Members
            .' WHEN public_flag = 2 THEN %3$d'                 // Friends
            .' WHEN public_flag = 3 THEN %4$d'                 // Private
            .' ELSE NULL END',
            Visibility::Open->value,
            Visibility::Members->value,
            Visibility::Friends->value,
            Visibility::Private->value,
        );
    }
}
