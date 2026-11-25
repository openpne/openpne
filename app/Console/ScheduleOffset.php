<?php

declare(strict_types=1);

namespace App\Console;

use InvalidArgumentException;

/**
 * Cron expressions for the scheduled tasks, each moved later by the host's offset
 * (docs/internals/runtime.md, "Scheduled tasks").
 */
final class ScheduleOffset
{
    public const ENV = 'OPENPNE_SCHEDULE_OFFSET_MINUTES';

    /** The last multiple of five before a daily task would meet its own next run. */
    public const MAX_MINUTES = 1435;

    private const STEP = 5;

    private const MINUTES_PER_DAY = 1440;

    /** Unset and empty are 0; anything else that is not a whole multiple of five up to MAX_MINUTES throws rather than being rounded. */
    public static function minutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $minutes = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A\d+\z/', $value) === 1 => (int) $value,
            default => null,
        };

        if ($minutes === null || $minutes < 0 || $minutes > self::MAX_MINUTES || $minutes % self::STEP !== 0) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a whole number of minutes from 0 to %d in steps of %d; got "%s".',
                self::ENV,
                self::MAX_MINUTES,
                self::STEP,
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return $minutes;
    }

    /** @param string $time HH:MM on the site's clock */
    public static function daily(string $time, int $offset): string
    {
        [, $hour, $minute] = self::shift($time, $offset);

        return "{$minute} {$hour} * * *";
    }

    /**
     * @param  int  $weekday  0 (Sunday) to 6
     * @param  string  $time  HH:MM on the site's clock
     */
    public static function weekly(int $weekday, string $time, int $offset): string
    {
        [$days, $hour, $minute] = self::shift($time, $offset);
        $day = ($weekday + $days) % 7;

        return "{$minute} {$hour} * * {$day}";
    }

    /** @return array{int, int, int} days carried, hour, minute */
    private static function shift(string $time, int $offset): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $total = $hour * 60 + $minute + $offset;
        $ofDay = $total % self::MINUTES_PER_DAY;

        return [intdiv($total, self::MINUTES_PER_DAY), intdiv($ofDay, 60), $ofDay % 60];
    }
}
