<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\SurfaceResolver;

/**
 * See docs/internals/classic-compatibility.md, "Design-customization compatibility".
 */
trait PhoneSurfaceSubheading
{
    public function getSubheading(): ?string
    {
        return SurfaceResolver::coexists() ? $this->phoneSurfaceNote() : null;
    }

    protected function phoneSurfaceNote(): string
    {
        return __('What you set here applies only to the Classic screens and does not reach phones: every phone visitor gets the Modern screens.');
    }
}
