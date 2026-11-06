<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/**
 * Hides a Modern-only admin screen when the install is classic_only, where its settings have no
 * effect; the mirror of HiddenWhenModernOnly, and like it refused on a direct URL too.
 */
trait HiddenWhenClassicOnly
{
    public static function canAccess(): bool
    {
        // Compose with the base authorization rather than replace it: this trait only ADDS the classic_only restriction.
        return parent::canAccess() && SurfaceResolver::modernAvailable();
    }
}
