<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `diary_comment_image` → OpenPNE 4 `diary_comment_images`. A verbatim join-row copy
 * (id / diary_comment_id / file_id). Unlike the community image steps there is no `number` column —
 * OpenPNE 3's diary_comment_image has none, and OpenPNE 4 keeps it that way (images order by id).
 * file_id (NOT NULL in the source) copies verbatim; FileUpgrade preserves file.id.
 */
class DiaryCommentImageUpgrade extends UpgradeStep
{
    protected string $source = 'diary_comment_image';

    protected string $target = 'diary_comment_images';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'diary_comment_id' => Column::source('diary_comment_id'),
            'file_id' => Column::source('file_id'),
        ];
    }
}
