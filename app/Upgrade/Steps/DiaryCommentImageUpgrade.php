<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `diary_comment_image` → OpenPNE 4 `diary_comment_images`, a verbatim join-row copy.
 * Neither schema has a `number` column (images order by id); file_id copies verbatim because
 * FileUpgrade preserves file.id.
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
