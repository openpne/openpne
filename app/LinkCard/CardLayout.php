<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Files\ImageDimensions;

/**
 * Decided here rather than per surface: Modern and Classic would otherwise each answer whether a
 * picture is big enough from their own copy of the rule, and the copies would drift silently.
 */
enum CardLayout: string
{
    /** A big landscape picture, drawn across the card under the words. */
    case Wide = 'wide';

    /** A small or square picture, drawn beside them — or no picture at all. */
    case Compact = 'compact';

    /**
     * Both sides ≥ 200 and at least 4:3, by integer cross-multiplication so a zero height cannot
     * divide; the `height >= 200` term is what keeps a wide, short banner (1000×150) out of the
     * full-width shape. `$width` and `$height` must be what the bytes render at (`files.width` /
     * `files.height`, EXIF Orientation applied, {@see ImageDimensions}), never what the container
     * declared.
     */
    public static function forImage(?int $width, ?int $height): self
    {
        if ($width === null || $height === null) {
            return self::Compact;
        }

        return $width >= 200 && $height >= 200 && $width * 3 >= $height * 4 ? self::Wide : self::Compact;
    }
}
