<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * Shared shape for the OpenPNE 3 post-image attachment tables (community topic / event and their
 * comments): a join row of post_id, file_id and the 1..N slot. OpenPNE 3 allowed a placeholder row
 * with a null file_id, which OpenPNE 4 requires, so the filter drops those.
 */
abstract class PostImageUpgrade extends UpgradeStep
{
    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'post_id' => Column::source('post_id'),
            'file_id' => Column::source('file_id'),
            'number' => Column::source('number'),
        ];
    }

    public function filter(): ?string
    {
        return '`file_id` IS NOT NULL';
    }

    public function filterColumns(): array
    {
        return ['file_id'];
    }
}
