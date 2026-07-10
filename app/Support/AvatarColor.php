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
 *
 * Nine families (gray + eight hues) in a light and a deep tier. Every light hex keeps slate-900
 * text >= 4.5:1 and every deep hex keeps white text >= 4.5:1, so the client's readable-text pick
 * lands uniformly per tier — never one odd badge in a row. The case order is family-paired
 * (light, deep) because the picker grid renders it verbatim: column-major on wide screens gives
 * one column per family, row-major on phones keeps each pair adjacent. The default (null) fills
 * the light-gray cell, which is why Gray has no Light twin here.
 */
enum AvatarColor: string
{
    case Gray = 'gray';

    case LightRed = 'light-red';

    case Red = 'red';

    case LightOrange = 'light-orange';

    case Orange = 'orange';

    case LightAmber = 'light-amber';

    case Amber = 'amber';

    case LightGreen = 'light-green';

    case Green = 'green';

    case LightTeal = 'light-teal';

    case Teal = 'teal';

    case LightBlue = 'light-blue';

    case Blue = 'blue';

    case LightViolet = 'light-violet';

    case Violet = 'violet';

    case LightPink = 'light-pink';

    case Pink = 'pink';

    public function hex(): string
    {
        return match ($this) {
            self::Gray => '#78716c',
            self::LightRed => '#fca5a5',
            self::Red => '#dc2626',
            self::LightOrange => '#fdba74',
            self::Orange => '#c2410c',
            self::LightAmber => '#fcd34d',
            // yellow-700 rather than amber-700: at white-text depths amber collapses into the same
            // brown as orange, so the deep cut leans mustard to keep the two families apart.
            self::Amber => '#a16207',
            self::LightGreen => '#86efac',
            self::Green => '#15803d',
            // cyan-300/700 rather than teal: darkened teal reads as another green, so the family
            // leans cyan to stay apart from Green.
            self::LightTeal => '#67e8f9',
            self::Teal => '#0e7490',
            self::LightBlue => '#93c5fd',
            self::Blue => '#2563eb',
            self::LightViolet => '#c4b5fd',
            self::Violet => '#7c3aed',
            self::LightPink => '#f9a8d4',
            self::Pink => '#db2777',
        };
    }

    /** Human-readable label key, translated via __()/t() (swatch aria-labels). */
    public function label(): string
    {
        return match ($this) {
            self::Gray => 'Dark gray',
            self::LightRed => 'Light red',
            self::Red => 'Red',
            self::LightOrange => 'Light orange',
            self::Orange => 'Orange',
            self::LightAmber => 'Light amber',
            self::Amber => 'Amber',
            self::LightGreen => 'Light green',
            self::Green => 'Green',
            self::LightTeal => 'Light teal',
            self::Teal => 'Teal',
            self::LightBlue => 'Light blue',
            self::Blue => 'Blue',
            self::LightViolet => 'Light violet',
            self::Violet => 'Violet',
            self::LightPink => 'Light pink',
            self::Pink => 'Pink',
        };
    }
}
