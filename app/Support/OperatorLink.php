<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * A link target an administrator typed, which the upgrade carries over from OpenPNE 3 verbatim: it
 * is drawn as a link only under a scheme that cannot run script in the page, and a site path only
 * when a browser reads it as one.
 */
final class OperatorLink
{
    public static function href(?string $url): ?string
    {
        $url = trim((string) $url);

        // A browser drops tabs and newlines from a URL and reads `\` as `/`, so `/\t/host` and `/\host` leave the site.
        if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
            return $url;
        }

        return self::isSitePath($url) ? $url : null;
    }

    public static function isSitePath(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//');
    }

    /** The admin-form rule that refuses what href() would draw without a link; an empty value is the field's own business. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && self::href($value) === null) {
                $fail(__('Enter an http(s):// URL, a mailto: address or a path starting with /.'));
            }
        };
    }
}
