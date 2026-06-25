<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Hides an admin screen when the site is Modern-only. The appearance screens configure the Classic
 * surface; on a `modern_only` site they would do nothing, so they are not shown (rather than shown with
 * a warning). Modern's own design settings live elsewhere (a future Customize editor), so this group is
 * the Classic design group and simply disappears under modern_only.
 */
trait HiddenWhenModernOnly
{
    public static function canAccess(): bool
    {
        return config('openpne.tenant_mode') !== 'modern_only' && parent::canAccess();
    }
}
