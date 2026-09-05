<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use Carbon\CarbonImmutable;

/**
 * A day of happenings does not start at midnight: it starts when the issue goes out and runs to the
 * next one (docs/internals/home-issues.md, "A day runs 06:00 → 06:00").
 */
final class HomeIssueDay
{
    /**
     * The site-clock hour a day turns over on, in the arithmetic form `PublishHomeIssue::TIME`
     * spells as a string for the schedule.
     */
    public const HOUR = 6;

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
