<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What a container walk proved about an image.
 *
 * Three values rather than a boolean, because "not animated" and "could not be read" must not
 * collapse into the same answer: a walk that ran past a limit, met a structure it does not know, or
 * read off the end has proved nothing, and routing that to the still pipeline — or the animated one
 * — hands a decoder exactly the input the walk was there to keep away from it.
 */
enum ImageStructure
{
    /** One frame, structure walked to the end. */
    case Still;

    /** More than one frame, or a container that declares animation features, walked to the end. */
    case Animated;

    /** Malformed, unsupported, or over a budget. Never delivered. */
    case Invalid;
}
