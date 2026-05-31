<?php

namespace App\Compat;

/**
 * One OpenPNE 3 route mapped to a Laravel route. Several OpenPNE 3 routes may map to one
 * Laravel route. `note` is a compatibility caveat, rendered in the parity matrix.
 *
 * `op3Route` / `op3Url` are null together when the Laravel route has no named OpenPNE 3
 * counterpart — reached through OpenPNE 3's global /:module/:action fallback (e.g. friend
 * link), or OpenPNE 4-native. Such maps still derive a body id but bind to no inventory entry.
 *
 * `method` is the HTTP method the Laravel route must serve. OpenPNE 3 routes mostly accept
 * ANY; narrowing to the concrete GET/POST the Classic adapter serves is intentional, so the
 * declared method is the contract the audit holds the live route to.
 *
 * `op3Action` is the OpenPNE 3 action this route renders. The Classic body id is derived from
 * it as `page_{module}_{action}` — the same hook OpenPNE 3 emitted — keyed on the OpenPNE 3
 * (module, action), not the Laravel route name. GET routes that render HTML carry it; POST
 * form submits render no `<body>`, so they leave it null.
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
    ) {}
}
