<?php

namespace App\Support;

/** The string values must equal SurfaceResolver::CLASSIC / MODERN, which compare against them as strings. */
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

    /** Caption key for the surface picker on either surface, translated via __()/t(). */
    public static function pickerNote(): string
    {
        return 'On a phone the site always shows the Modern design; this choice applies to desktop browsers.';
    }
}
