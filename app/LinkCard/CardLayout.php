<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;

/**
 * Which of the two shapes a card is drawn in.
 *
 * Decided here rather than per surface, for the reason the gates in {@see LinkCardSerializer} are:
 * Modern and Classic would otherwise each answer "is this picture big enough" from their own copy of
 * the rule, and the copies would drift silently — one surface enlarging a logo the other keeps as a
 * tile. What decides is the picture; see {@see LinkCard::hasLargeImage()}.
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
}
