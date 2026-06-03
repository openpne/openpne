<?php

namespace App\Compat;

/** Whether a screen's surface element is present on the Classic adapter, and to what degree. */
enum ScreenStatus: string
{
    case Ported = 'ported';     // present and faithful
    case Partial = 'partial';   // present but missing a sub-behaviour
    case Missing = 'missing';   // not built yet, no blocker
    case Deferred = 'deferred'; // intentionally waiting on another feature

    public function icon(): string
    {
        return match ($this) {
            self::Ported => '✅',
            self::Partial => '⚠️',
            self::Missing => '❌',
            self::Deferred => '🚫',
        };
    }

    /**
     * Icon plus its word. The label is not decoration: Symfony Console strips the warning sign
     * from ⚠️ on output, so the icon alone would render Partial as an unreadable bare selector —
     * the word keeps every status legible regardless of glyph survival.
     */
    public function display(): string
    {
        return $this->icon().' '.$this->value;
    }

    /**
     * Anything short of a faithful port must record why (a dependency or how-to-close note),
     * mirroring the doc's "record a gap with its reason". A faithful port needs none.
     */
    public function requiresNote(): bool
    {
        return $this !== self::Ported;
    }
}
