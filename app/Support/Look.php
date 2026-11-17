<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A named set of deviations from the standard Modern layout; a screen a look says nothing about
 * renders standard (docs/internals/looks.md).
 */
enum Look: string
{
    /** The Modern layout as shipped. */
    case Standard = 'standard';

    /** The experiment that renders member pages and group tops in one shared shape. */
    case Unified = 'unified';

    /** The experiment that moves finding your way into one breadcrumb header, with no back button. */
    case Tabbed = 'tabbed';
}
