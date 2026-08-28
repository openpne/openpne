<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use Carbon\CarbonImmutable;

/**
 * Which day of happenings an instant belongs to.
 *
 * **A day here does not start at midnight.** It starts when the issue goes out and runs to the next
 * one, so what a reader is handed at 06:00 is everything since 06:00 the morning before — and that
 * whole stretch is one day. Dating it by the calendar instead would file last evening under today
 * and headline the page with a date none of it happened on.
 */
final class HomeIssueDay
{
    /**
     * The site-clock hour a day turns over on — the hour the publisher runs at, spelled as a number.
     * Pinned against `PublishHomeIssue::TIME` in PublishHomeIssueCommandTest, since the schedule
     * needs the string form and this needs the arithmetic one.
     */
    public const HOUR = 6;

    /** The day $instant falls in: the calendar day it lands on once the boundary is taken off. */
    public static function of(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->subHours(self::HOUR)->startOfDay();
    }

    /**
     * The most recent day an issue can cover: the one that closed at the last boundary to pass.
     * A page dated on or after it is showing what there is; one dated before it has missed a day.
     */
    public static function latest(CarbonImmutable $now): CarbonImmutable
    {
        return self::of($now)->subDay();
    }

    /** The stretch $day covers on the site's clock: (D 06:00, D+1 06:00]. */
    public static function window(CarbonImmutable $day): HomeIssueWindow
    {
        $start = $day->startOfDay()->addHours(self::HOUR);

        return new HomeIssueWindow($start, $start->addDay());
    }
}
