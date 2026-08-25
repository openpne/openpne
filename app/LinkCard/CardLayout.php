<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Files\ImageDimensions;

/**
 * Which of the two shapes a card is drawn in.
 *
 * Decided here rather than per surface, for the reason the gates in {@see LinkCardSerializer} are:
 * Modern and Classic would otherwise each answer "is this picture big enough" from their own copy of
 * the rule, and the copies would drift silently — one surface enlarging a logo the other keeps as a
 * tile.
 *
 * The value crosses to both renderers as a string, so it is written down once here and the parity
 * test (tests/Feature/Frontend/LinkCardLayoutParityTest) holds them to it.
 */
enum CardLayout: string
{
    /** A big landscape picture, drawn across the card under the words. */
    case Wide = 'wide';

    /** A small or square picture, drawn beside them — or no picture at all. */
    case Compact = 'compact';

    /**
     * The shape a picture of this size asks for.
     *
     * Every chat and feed client that draws these switches on the picture itself — a big landscape
     * image is a preview, a small or square one is an icon — so the reader is not shown a 64px logo
     * blown across the card, or a magazine cover shrunk into a corner. The threshold is **ours**,
     * assembled from two of them rather than copied: Signal requires both sides ≥ 200 and merely
     * not-square (so it enlarges portraits too), Mattermost requires width ≥ 150 and 4:3 with no
     * lower bound on height. Neither has the `height >= 200` term; that one keeps a wide, short
     * banner — 1000×150 — out of the full-width shape, where it draws as a stripe.
     *
     * Integer cross-multiplication rather than a ratio: the boundary is exact and visible, and a
     * zero height cannot divide.
     *
     * **The dimensions must be the ones the bytes render at** — `files.width` / `files.height`, EXIF
     * Orientation applied ({@see ImageDimensions}) — never what a container declared. For
     * a sideways-shot JPEG the two disagree by a quarter turn, and this predicate, the reserved
     * aspect box and the `w` descriptors would be wrong together.
     */
    public static function forImage(?int $width, ?int $height): self
    {
        if ($width === null || $height === null) {
            return self::Compact;
        }

        return $width >= 200 && $height >= 200 && $width * 3 >= $height * 4 ? self::Wide : self::Compact;
    }
}
