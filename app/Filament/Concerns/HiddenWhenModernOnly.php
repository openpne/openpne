<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/**
 * Hides an admin screen when no one can reach the Classic surface. The appearance screens configure the
 * Classic shell; when the site renders only Modern they would do nothing, so they are not shown (rather
 * than shown with a warning). The predicate is SurfaceResolver::classicReachable() — not a bare
 * modern_only check — so a feature pinned off Modern (which still renders Classic under modern_only)
 * keeps these settings visible. Modern's own design settings live elsewhere (a future Customize editor),
 * so this group is the Classic design group and simply disappears when the site is purely Modern.
 */
trait HiddenWhenModernOnly
{
    public static function canAccess(): bool
    {
        return SurfaceResolver::classicReachable() && parent::canAccess();
    }
}
