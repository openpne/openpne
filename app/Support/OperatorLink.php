<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A link target an administrator typed, which the upgrade carries over from OpenPNE 3 verbatim: it
 * is drawn as a link only under a scheme that cannot run script in the page.
 */
final class OperatorLink
{
    /** The value for an href, or null when $url is to be drawn without a link. */
    public static function href(?string $url): ?string
    {
        $url = trim((string) $url);

        // A browser drops tabs and newlines from a URL before reading it, so `/\t/host` leaves the site.
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
            return $url;
        }

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : null;
    }
}
