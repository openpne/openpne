<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One value for both whether Classic is served and which surface an undecided viewer gets, so a
 * default surface under modern_only cannot be stored. The upgrade establishes classic_default.
 */
enum SurfaceMode: string
{
    /** Modern only — Classic is not served and its admin/member surfaces are hidden. */
    case ModernOnly = 'modern_only';

    /** Classic and Modern coexist; an undecided viewer gets Classic (the OpenPNE 3 → 4 default). */
    case ClassicDefault = 'classic_default';

    /** Classic and Modern coexist; an undecided viewer gets Modern. */
    case ModernDefault = 'modern_default';

    /** Whether the Classic surface is served at all. Only modern_only turns it off. */
    public function classicAvailable(): bool
    {
        return $this !== self::ModernOnly;
    }

    /** The surface an undecided viewer gets on a canonical route (irrelevant under modern_only). */
    public function defaultSurface(): Surface
    {
        return $this === self::ClassicDefault ? Surface::Classic : Surface::Modern;
    }
}
