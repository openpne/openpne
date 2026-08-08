<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Files\ImageInspector;
use App\Files\ImageStructure;

/**
 * Whether a remote image is provably a single still frame.
 *
 * A card shows one still picture, so anything the walk cannot prove is one frame is refused — an
 * animation, a container that did not parse, a file over a budget. The walk itself, and the reason
 * it must not collapse "not animated" and "could not be read" into one answer, live in
 * {@see ImageInspector}; this only narrows its three-way result to the one case a card
 * accepts.
 */
final class ImageContainer
{
    /** Whether $bytes is provably a single-frame image of type $mime. */
    public static function isSafeStill(string $bytes, string $mime): bool
    {
        return ImageInspector::inspect($bytes, $mime)->structure === ImageStructure::Still;
    }
}
