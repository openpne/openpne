<?php

namespace App\Compat;

/**
 * `op3Route` and `op3Url` are null together for a route with no named OpenPNE 3 counterpart
 * (fallback-reached or OpenPNE 4-native), which still derives a body id but binds to no
 * inventory entry. `method` is deliberately the one GET or POST the Classic adapter serves,
 * not the ANY most OpenPNE 3 routes accepted.
 */
final class RouteMap
{
    public function __construct(
        public readonly ?string $op3Route,
        public readonly ?string $op3Url,
        public readonly string $laravelRoute,
        public readonly string $method = 'GET',
        public readonly ?string $note = null,
        public readonly ?string $op3Action = null,
        public readonly ?string $op3Module = null,
    ) {}
}
