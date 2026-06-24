<?php

namespace App\Support;

/**
 * The two rendering surfaces a feature route can serve: the OpenPNE 3-faithful, server-rendered
 * Classic UI and the React/Inertia Modern UI. SurfaceResolver decides which one a request gets; a
 * member can pin a durable choice (PreferenceKey::PreferredSurface), absent which the session
 * toggle and tenant default apply. The string values match SurfaceResolver's CLASSIC/MODERN.
 */
enum Surface: string
{
    case Classic = 'classic';

    case Modern = 'modern';

    /** Human-readable label key, translated via __()/t() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Classic',
            self::Modern => 'Modern',
        };
    }

    /** One-line description key for the surface picker, translated via __()/t(). */
    public function description(): string
    {
        return match ($this) {
            self::Classic => 'Traditional design, suited to desktop.',
            self::Modern => 'New mobile-first design.',
        };
    }
}
