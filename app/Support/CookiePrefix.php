<?php

namespace App\Support;

final class CookiePrefix
{
    /**
     * A browser rejects a prefixed cookie that breaks its prefix's terms — Secure for both, and for
     * `__Host-` also no Domain attribute and Path=/ — so the prefix follows the session config every
     * cookie here is issued under (docs/internals/sessions.md, "Cookie-name prefix").
     */
    public static function apply(string $base): string
    {
        if (! config('session.secure') || str_starts_with($base, '__Secure-') || str_starts_with($base, '__Host-')) {
            return $base;
        }

        // Symfony's Cookie omits an empty Domain and writes an empty path as `/`.
        $hostOnly = in_array(config('session.domain'), [null, ''], true)
            && in_array(config('session.path'), [null, '', '/'], true);

        return ($hostOnly ? '__Host-' : '__Secure-').$base;
    }
}
