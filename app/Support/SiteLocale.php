<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;

/**
 * The language the site itself writes in — a body stored once and read by everyone, never the
 * request's. Read from openpne.site_locale because SetLocale rewrites app.locale on every request.
 */
final class SiteLocale
{
    public static function default(): string
    {
        $locale = (string) config('openpne.site_locale');

        return in_array($locale, SetLocale::SUPPORTED_LOCALES, true) ? $locale : SetLocale::SUPPORTED_LOCALES[0];
    }
}
