<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/**
 * Hides a Classic-only admin screen when the install is modern_only, where its settings have no
 * effect. Filament consults canAccess() for navigation and direct-URL authorization alike, so a
 * bookmarked URL is refused too.
 */
trait HiddenWhenModernOnly
{
    public static function canAccess(): bool
    {
        // Compose with the base authorization rather than replace it, so a future policy / role gate on
        // the screen still applies — this trait only ADDS the modern_only restriction.
        return parent::canAccess() && SurfaceResolver::classicAvailable();
    }
}
