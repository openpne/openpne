<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One value for whether each surface is served and which one an undecided viewer gets, so a
 * default surface under a single-surface mode cannot be stored. The upgrade establishes classic_default.
 */
enum SurfaceMode: string
{
    /** Modern only — Classic is not served and its admin/member surfaces are hidden. */
    case ModernOnly = 'modern_only';

    /** Classic only — Modern is not served, a phone client included, and its admin/member surfaces are hidden. */
    case ClassicOnly = 'classic_only';

    /** Classic and Modern coexist; an undecided viewer gets Classic (the OpenPNE 3 → 4 default). */
    case ClassicDefault = 'classic_default';

    /** Classic and Modern coexist; an undecided viewer gets Modern. */
    case ModernDefault = 'modern_default';

    /** Whether the Classic surface is served at all. Only modern_only turns it off. */
    public function classicAvailable(): bool
    {
        return $this !== self::ModernOnly;
    }

    /** Whether the Modern surface is served at all. Only classic_only turns it off. */
    public function modernAvailable(): bool
    {
        return $this !== self::ClassicOnly;
    }

    /** Whether both surfaces are served, which is when a member has a surface to choose. */
    public function coexists(): bool
    {
        return $this->classicAvailable() && $this->modernAvailable();
    }

    /** The surface an undecided viewer gets on a canonical route; under a single-surface mode, that mode's one surface. */
    public function defaultSurface(): Surface
    {
        return $this->modernAvailable() && $this !== self::ClassicDefault ? Surface::Modern : Surface::Classic;
    }
}
