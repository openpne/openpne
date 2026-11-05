<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A fixed position in a shell where a package may draw HTML (docs/internals/layout-hooks.md, "Positions").
 */
enum LayoutHook: string
{
    case ClassicHead = 'classic.head';
    case ClassicHeaderEnd = 'classic.header_end';
    case ClassicLayoutStart = 'classic.layout_start';
    case ClassicLeftEnd = 'classic.left_end';
    case ClassicCenterEnd = 'classic.center_end';
    case ClassicLayoutEnd = 'classic.layout_end';
    case ClassicSideBannerEnd = 'classic.side_banner_end';
    case ClassicFooterEnd = 'classic.footer_end';
    case ClassicBodyEnd = 'classic.body_end';

    case ModernHead = 'modern.head';
    case ModernBodyEnd = 'modern.body_end';
    case ModernLeftNavEnd = 'modern.left_nav_end';
    case ModernMainStart = 'modern.main_start';
    case ModernMainEnd = 'modern.main_end';
    case ModernRightRailEnd = 'modern.right_rail_end';
    case ModernAuthStart = 'modern.auth_start';
    case ModernAuthEnd = 'modern.auth_end';

    public function isClientRendered(): bool
    {
        return str_starts_with($this->value, 'modern.') && $this !== self::ModernHead && $this !== self::ModernBodyEnd;
    }
}
