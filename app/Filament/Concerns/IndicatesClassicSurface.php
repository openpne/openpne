<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Marks an admin screen as governing the Classic surface only, via a subheading note that adapts to the
 * configured default surface. Applied to the appearance screens (gadget layout/gadgets, navigation,
 * banner images, design) so an operator editing them knows whether the change reaches members — most
 * importantly, a `modern_only` site is warned that these Classic settings do not affect what is shown.
 */
trait IndicatesClassicSurface
{
    public function getSubheading(): string|Htmlable|null
    {
        if (config('openpne.tenant_mode') === 'modern_only') {
            return '⚠ '.__('The site shows the Modern view, so these Classic settings do not affect what members currently see.');
        }

        return config('openpne.tenant_default_surface') === 'modern'
            ? __('These settings affect the Classic view (members see Modern by default; Classic is used when they switch).')
            : __('These settings affect the Classic view (members see Classic by default).');
    }
}
