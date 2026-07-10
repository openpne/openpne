<?php

namespace App\Support;

/**
 * A member's chosen color for the no-image initial badge. null (no row value) = the neutral badge;
 * a chosen color is deliberate self-expression, so it renders as a solid fill — the default stays
 * achromatic precisely because the system must not assign personality the member didn't pick.
 *
 * The slugs are the stored `members.avatar_color` values and the hexes are what serializers ship;
 * both live here so palette changes stay one edit. The column is a free string, not this enum, so
 * a later "any color code" tier can store `#rrggbb` literals without a schema change.
 */
enum AvatarColor: string
{
    case Red = 'red';

    case Orange = 'orange';

    case Amber = 'amber';

    case Yellow = 'yellow';

    case Lime = 'lime';

    case Emerald = 'emerald';

    case Teal = 'teal';

    case Cyan = 'cyan';

    case Blue = 'blue';

    case Indigo = 'indigo';

    case Purple = 'purple';

    case Pink = 'pink';

    /**
     * Mid-lightness (Tailwind 500) hues: every one lets the client's readable-text pick (white or
     * slate-900) clear WCAG 4.5:1 in both color modes.
     */
    public function hex(): string
    {
        return match ($this) {
            self::Red => '#ef4444',
            self::Orange => '#f97316',
            self::Amber => '#f59e0b',
            self::Yellow => '#eab308',
            self::Lime => '#84cc16',
            self::Emerald => '#10b981',
            self::Teal => '#14b8a6',
            self::Cyan => '#06b6d4',
            self::Blue => '#3b82f6',
            self::Indigo => '#6366f1',
            self::Purple => '#a855f7',
            self::Pink => '#ec4899',
        };
    }

    /** Human-readable label key, translated via __()/t() (swatch aria-labels). */
    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red',
            self::Orange => 'Orange',
            self::Amber => 'Amber',
            self::Yellow => 'Yellow',
            self::Lime => 'Lime',
            self::Emerald => 'Emerald',
            self::Teal => 'Teal',
            self::Cyan => 'Cyan',
            self::Blue => 'Blue',
            self::Indigo => 'Indigo',
            self::Purple => 'Purple',
            self::Pink => 'Pink',
        };
    }
}
