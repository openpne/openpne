<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How a single install serves the two rendering surfaces (App\Support\Surface). Read by
 * SurfaceResolver and stored as the SnsSettingKey::SurfaceMode value. A fresh OpenPNE 4 install
 * defaults to modern_only (Classic never existed); the OpenPNE 3 → 4 upgrade establishes
 * classic_default so a migrated site keeps its Classic look until the operator switches.
 *
 * The two coexistence modes carry the default surface for an undecided viewer; modern_only carries
 * none because Classic is not served at all. Folding "is Classic available?" and "which surface is
 * the default?" into one value removes the dead combination the two former flags allowed — a default
 * surface that modern_only silently ignored.
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
