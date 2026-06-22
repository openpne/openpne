<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `banner_image` → OpenPNE 4 `banner_images` (the image pool a banner draws from).
 *
 * A verbatim copy: file_id resolves because FileUpgrade keeps file.id, and FileUpgrade owns each such
 * file as `bannerImage` keyed by this row's id.
 */
class BannerImageUpgrade extends UpgradeStep
{
    protected string $source = 'banner_image';

    protected string $target = 'banner_images';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'file_id' => Column::source('file_id'),
            'url' => Column::source('url'),
            'name' => Column::source('name'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
