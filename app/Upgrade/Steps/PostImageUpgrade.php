<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * Shared shape for the OpenPNE 3 post-image attachment tables (community topic / event and their
 * comments): a pure join row of post_id → the post, file_id → the migrated file, number → the 1..N
 * slot. Each concrete step only names its source/target tables.
 *
 * OpenPNE 3 allowed a placeholder row with a null file_id; OpenPNE 4 requires the file, so those rows
 * are dropped by the filter. file.id is preserved by FileUpgrade, so file_id copies verbatim.
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
