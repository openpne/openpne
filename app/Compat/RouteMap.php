<?php

namespace App\Compat;

/**
 * One OpenPNE 3 route mapped to an OpenPNE 4 (Laravel) route.
 *
 * Several OpenPNE 3 routes can map to one OpenPNE 4 route (e.g. the "mine" and
 * "member" diary lists both land on an optional-parameter route). `note` records a
 * compatibility caveat when one is worth surfacing.
 */
final class RouteMap
{
    public function __construct(
        public readonly string $op3Route,   // OpenPNE 3 route name
        public readonly string $op3Url,     // its URL pattern
        public readonly string $op4Route,   // OpenPNE 4 route name
        public readonly ?string $note = null,
    ) {}
}
