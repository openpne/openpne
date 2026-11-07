<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/** Hides a Modern-only admin screen under classic_only, where its settings have no effect; the mirror of HiddenWhenModernOnly. */
trait HiddenWhenClassicOnly
{
    public static function canAccess(): bool
    {
        return parent::canAccess() && SurfaceResolver::modernAvailable();
    }
}
