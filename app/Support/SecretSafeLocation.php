<?php

declare(strict_types=1);

namespace App\Support;

use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An Inertia location to the request's own URL, which may carry a secret, with the headers NoStore and
 * NoReferrer give such a page (docs/internals/sessions.md, "The previous URL").
 */
final class SecretSafeLocation
{
    public static function to(string $url): Response
    {
        return self::protect(Inertia::location($url));
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-transform, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
