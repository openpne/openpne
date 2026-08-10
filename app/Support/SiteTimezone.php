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
     * written and read in a zone nobody chose.
     *
     * The canonical group only — every one of its zones is also formattable by the client's Intl, which
     * is what lets both surfaces share the value. `ALL_WITH_BC` cannot be used for this: it carries
     * tzdata filenames (`leapseconds`, `localtime`, `tzdata.zi`, `Factory`) that Intl rejects and that
     * make date_default_timezone_get raise "Timezone database is corrupt". Non-canonical aliases
     * (`Etc/UTC`, `GMT`, `Japan`) are rejected on purpose — configure the canonical name instead.
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
