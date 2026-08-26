<?php

namespace App\Features\Timeline\Queries;

/**
 * The page a rows fragment serves: the feeds' own size when a caller names none, and the most a
 * caller may ask for. A gadget's limit is held to the same ceiling, or its load-more would ask for
 * a page the fragment refuses.
 */
final class RowsPage
{
    public const DEFAULT = 20;

    public const MAX = 50;
}
