<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An unrenderable uri, such as an unconverted OpenPNE 3 token like `@homepage`, is kept verbatim
 * by the upgrade and hidden by the renderer rather than rejected.
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
