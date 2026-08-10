<?php

namespace App\Support;

use DateTimeZone;
use InvalidArgumentException;

/**
 * The site's clock, as configured by APP_TIMEZONE. Storage and display share it — there is no
 * per-member timezone — so a wrong value is not a formatting nit but a reinterpretation of every
 * DATETIME in the database (docs/internals/runtime.md).
 */
final class SiteTimezone
{
    /**
     * A bad name would otherwise pass silently: LoadConfiguration's date_default_timezone_set only
     * warns on one it cannot use and leaves the previous zone in place, so every timestamp would be
     * written and read in a zone nobody chose. Restricted to PHP's IANA list, a strict subset of what
     * the client's Intl accepts, so a value that boots is always formattable in the browser too.
     *
     * @throws InvalidArgumentException
     */
    public static function assertUsable(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException("APP_TIMEZONE [{$timezone}] is not an IANA timezone name.");
        }
    }
}
