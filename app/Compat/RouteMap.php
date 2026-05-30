<?php

namespace App\Compat;

/**
 * One OpenPNE 3 route mapped to a Laravel route. Several OpenPNE 3 routes may map to one
 * Laravel route. `note` is a compatibility caveat, rendered in the parity matrix.
 */
final class RouteMap
{
    public function __construct(
        public readonly string $op3Route,
        public readonly string $op3Url,
        public readonly string $laravelRoute,
        public readonly ?string $note = null,
    ) {}
}
