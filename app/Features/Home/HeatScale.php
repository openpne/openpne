<?php

declare(strict_types=1);

namespace App\Features\Home;

/**
 * How dark a day is drawn beside the month's other days
 * (docs/internals/home-issues.md, "The month page").
 */
final class HeatScale
{
    public const LEVELS = 4;

    /**
     * A rank and not a share of the busiest: one very busy day must not flatten the rest. A tie
     * takes the lower level, so a month of equal days is drawn at 1 throughout.
     *
     * @param  array<int, int>  $activity
     * @return array<int, int<0, 4>> under the same keys, 0 where there was no activity
     */
    public static function levels(array $activity): array
    {
        $ranked = array_filter($activity, fn (int $amount): bool => $amount > 0);
        $levels = [];

        foreach ($activity as $key => $amount) {
            if ($amount <= 0) {
                $levels[$key] = 0;

                continue;
            }

            $below = count(array_filter($ranked, fn (int $other): bool => $other < $amount));

            $levels[$key] = min(self::LEVELS, 1 + intdiv(self::LEVELS * $below, count($ranked)));
        }

        return $levels;
    }
}
