<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether the request comes from a phone-class browser, by client hint then user agent (docs/internals/feature-modules.md, "Surface selection").
 * Tablets are deliberately not phones: iPadOS reports itself as Macintosh and Android tablets omit the Mobile token.
 */
final class PhoneClient
{
    public static function matches(Request $request): bool
    {
        // Chromium sends this low-entropy hint unasked on secure contexts and answers "?0" in desktop-site mode.
        $hint = $request->header('Sec-CH-UA-Mobile');
        if ($hint !== null) {
            return $hint === '?1';
        }

        $userAgent = (string) $request->userAgent();

        return preg_match('/iPhone|iPod/', $userAgent) === 1
            || (str_contains($userAgent, 'Android') && str_contains($userAgent, 'Mobile'));
    }
}
