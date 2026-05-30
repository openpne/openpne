<?php

namespace App\Compat;

/**
 * One OpenPNE 3 route mapped to a Laravel route. Several OpenPNE 3 routes may map to one
 * Laravel route. `note` is a compatibility caveat, rendered in the parity matrix.
 *
 * `method` is the HTTP method the Laravel route must serve. OpenPNE 3 routes mostly accept
 * ANY; narrowing to the concrete GET/POST the Classic adapter serves is intentional, so the
 * declared method is the contract the audit holds the live route to.
 */
final class RouteMap
{
    public function __construct(
        public readonly string $op3Route,
        public readonly string $op3Url,
        public readonly string $laravelRoute,
        public readonly string $method = 'GET',
        public readonly ?string $note = null,
    ) {}
}
