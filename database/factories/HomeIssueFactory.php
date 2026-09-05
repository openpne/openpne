<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Home\Data\HomeIssueDay;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeIssue>
 */
class HomeIssueFactory extends Factory
{
    protected $model = HomeIssue::class;

    /**
     * Both unique columns are per-process counters rather than random values: `number` and
     * `issue_date` are unique in the schema, so a test making two issues without saying anything
     * about either would otherwise fail on a collision it did not ask for. Counting also makes the
     * issues come out in publication order, which is the order every reader of them assumes.
     */
    private static int $published = 0;

    public function definition(): array
    {
        $number = ++self::$published;

        // Walking forward from a fixed distance back keeps the whole run in the past — a published
        // issue dated tomorrow is a state the publisher cannot produce.
        $date = CarbonImmutable::today(config('app.timezone'))->subDays(365)->addDays($number - 1);

        // The day's own stretch: an issue is dated by the last day its window covers, not the first.
        $window = HomeIssueDay::window($date);

        return [
            'number' => $number,
            'issue_date' => $date,
            'window_start' => $window->start,
            'published_at' => $window->end,
        ];
    }
}
