<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Database\SearchCollation;

/** Filament reads this per resource, out of reach of the panel's component defaults. */
trait FoldsGlobalSearchCase
{
    public static function isGlobalSearchForcedCaseInsensitive(): ?bool
    {
        return SearchCollation::forcesCaseFolding();
    }
}
