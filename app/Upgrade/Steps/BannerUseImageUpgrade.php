<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `banner_use_image` → OpenPNE 4 `banner_use_images` (the banner↔image placement pivot).
 *
 * A verbatim copy; both foreign keys resolve because BannerUpgrade and BannerImageUpgrade keep ids.
 */
class BannerUseImageUpgrade extends UpgradeStep
{
    protected string $source = 'banner_use_image';

    protected string $target = 'banner_use_images';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'banner_id' => Column::source('banner_id'),
            'banner_image_id' => Column::source('banner_image_id'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
