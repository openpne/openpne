<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The per-site brand color (SnsSettingKey::BrandColor): its accepted form and the foreground that
 * stays readable on it.
 *
 * The foreground is pure white or pure black — never an off-black. Against a white/slate-900 pair a
 * mid-tone hue such as #0088aa clears 4.5:1 on neither side; pure black and white keep the worst case
 * at 4.58:1, so any color an administrator picks still carries AA text on the fill.
 * resources/js/lib/identity-mark.ts is the client twin of this comparison.
 */
class BrandColor
{
    /** The built-in color used wherever no brand color is set (browser chrome, the Modern brand mark). */
    public const DEFAULT = '#2563eb';

    public const FOREGROUND_ON_DARK = '#ffffff';

    public const FOREGROUND_ON_LIGHT = '#000000';

    public static function isValid(string $hex): bool
    {
        return preg_match('/\A#[0-9a-fA-F]{6}\z/', $hex) === 1;
    }

    /**
     * White or black, whichever has the higher WCAG contrast ratio against $hex. A ratio comparison
     * (not a luminance threshold) is what keeps mid-gray backgrounds readable. Invalid input falls
     * back to white, as the client twin does.
     */
    public static function readableForeground(string $hex): string
    {
        if (! self::isValid($hex)) {
            return self::FOREGROUND_ON_DARK;
        }

        $background = self::relativeLuminance($hex);
        $onBlack = self::contrastRatio(0.0, $background);
        $onWhite = self::contrastRatio(1.0, $background);

        return $onBlack >= $onWhite ? self::FOREGROUND_ON_LIGHT : self::FOREGROUND_ON_DARK;
    }

    private static function relativeLuminance(string $hex): float
    {
        $r = self::srgbToLinear(hexdec(substr($hex, 1, 2)) / 255);
        $g = self::srgbToLinear(hexdec(substr($hex, 3, 2)) / 255);
        $b = self::srgbToLinear(hexdec(substr($hex, 5, 2)) / 255);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function srgbToLinear(float $channel): float
    {
        return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function contrastRatio(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
