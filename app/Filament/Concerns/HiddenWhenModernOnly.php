<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/**
 * Hides a Classic-only admin screen (the "Appearance (Classic)" group) when the install is
 * modern_only — there the Classic surface is never served, so its settings have no effect and
 * showing them would only mislead. Filament consults canAccess() for both navigation registration
 * and direct-URL authorization, so a bookmarked /admin/... URL 403s as well. On classic_default /
 * modern_default the screen is visible.
 */
trait HiddenWhenModernOnly
{
    public static function canAccess(): bool
    {
        return SurfaceResolver::classicAvailable();
    }
}
