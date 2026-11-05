<?php

declare(strict_types=1);

namespace App\Support;

/** See docs/internals/site-icon.md, "Three states". */
enum FaviconFallback: string
{
    case Generated = 'generated';

    case None = 'none';
}
