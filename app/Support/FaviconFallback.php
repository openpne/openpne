<?php

declare(strict_types=1);

namespace App\Support;

/** The browser-tab icon of a site with no favicon uploaded (docs/internals/site-icon.md). */
enum FaviconFallback: string
{
    case Generated = 'generated';

    case None = 'none';
}
