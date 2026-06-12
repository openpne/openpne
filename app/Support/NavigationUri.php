<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Allow-list for a navigation item's stored uri. The single source of truth shared by the
 * renderer (App\Services\NavigationService skips anything not renderable), the admin form
 * (App\Filament — rejects bad input), and the upgrade tool's tests.
 *
 * A renderable uri is either a single-slash internal path (a protocol-relative `//host` is
 * rejected) or an `http(s)://` URL — with no whitespace or control characters. Everything else
 * (an unconverted OpenPNE 3 token like `@homepage` or `diary/index`, a non-http scheme such as
 * `ftp://`, `javascript:`) is treated as unresolved: the upgrade keeps it verbatim and the
 * renderer hides it.
 */
final class NavigationUri
{
    public static function isRenderable(string $uri): bool
    {
        if ($uri === '' || preg_match('/[\s\x00-\x1f\x7f]/', $uri) === 1) {
            return false;
        }

        if (self::isExternal($uri)) {
            return true;
        }

        return str_starts_with($uri, '/') && ! str_starts_with($uri, '//');
    }

    /** An http(s) URL, which the renderer links to without a route-existence check. */
    public static function isExternal(string $uri): bool
    {
        return preg_match('#^https?://#i', $uri) === 1;
    }
}
