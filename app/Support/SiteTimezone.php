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
     * LoadConfiguration's date_default_timezone_set only warns on a name it cannot use and keeps the
     * previous zone, so a bad APP_TIMEZONE would pass silently. The canonical group only: `ALL_WITH_BC`
     * carries tzdata filenames that the client's Intl rejects and that corrupt date_default_timezone_get.
     *
     * @throws InvalidArgumentException
     */
    public static function assertUsable(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("APP_TIMEZONE [{$timezone}] is not a canonical IANA timezone name.");
        }
    }
}
