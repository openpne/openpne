<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `banner` → OpenPNE 4 `banners` (a fixed top placement, operator HTML or an image pool).
 *
 * OpenPNE 3's banner has no timestamps; OpenPNE 4's are nullable, so they rely on their default. The
 * I18n caption (banner_translation) is not carried — it was an admin-only label, never rendered.
 */
class BannerUpgrade extends UpgradeStep
{
    protected string $source = 'banner';

    protected string $target = 'banners';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'is_use_html' => Column::source('is_use_html'),
            'html' => Column::source('html'),
        ];
    }

    public function targetDefaults(): array
    {
        // OpenPNE 3 `banner` has no created_at / updated_at; the nullable columns rely on their default.
        return ['created_at', 'updated_at'];
    }
}
