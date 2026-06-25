<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Marks an admin screen as governing the Classic surface only, via a subheading note. Applied to the
 * appearance screens (gadget layout/gadgets, navigation, banner images, design) so an operator never
 * assumes a Classic-only setting also changes Modern. The "Display mode" page explains the full model.
 */
trait IndicatesClassicSurface
{
    public function getSubheading(): string|Htmlable|null
    {
        return __('These settings affect the Classic view only.');
    }
}
