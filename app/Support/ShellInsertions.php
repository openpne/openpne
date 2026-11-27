<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Withholds, for one request, everything the shells draw that the site's operators or packages
 * supply: operator HTML, banners, side-banner gadgets, custom CSS and layout hooks
 * (docs/internals/administrators.md, "The registration screen").
 */
final class ShellInsertions
{
    private const ATTRIBUTE = 'openpne.shell_insertions_withheld';

    public static function withhold(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }

    public static function withheld(): bool
    {
        return app()->bound('request') && request()->attributes->get(self::ATTRIBUTE) === true;
    }
}
