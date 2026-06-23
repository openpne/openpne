<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;

/**
 * OpenPNE 3 `diary_image` → OpenPNE 4 `diary_images`. Same join shape as the community
 * PostImageUpgrade steps, but the OpenPNE 3 owner column is `diary_id` (not `post_id`), so
 * columns() is overridden; file_id copies verbatim (FileUpgrade preserves file.id).
 */
class DiaryImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'diary_image';

    protected string $target = 'diary_images';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'diary_id' => Column::source('diary_id'),
            'file_id' => Column::source('file_id'),
            'number' => Column::source('number'),
        ];
    }
}
