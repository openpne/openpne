<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Queries\ListHomeIssueMonths;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListHomeIssueMonthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_month_is_counted_newest_first(): void
    {
        foreach (['2025-12-31', '2026-08-01', '2026-08-31', '2026-09-15'] as $date) {
            $this->publishOn($date);
        }

        $this->assertSame(
            [
                ['year' => 2026, 'month' => 9, 'count' => 1],
                ['year' => 2026, 'month' => 8, 'count' => 2],
                ['year' => 2025, 'month' => 12, 'count' => 1],
            ],
            app(ListHomeIssueMonths::class)(),
        );
    }

    public function test_an_issue_is_counted_in_the_month_it_is_dated_in(): void
    {
        HomeIssue::factory()->create([
            'issue_date' => '2026-08-03',
            'window_start' => CarbonImmutable::parse('2026-07-28 06:00:00'),
            'published_at' => CarbonImmutable::parse('2026-08-04 06:00:00'),
        ]);

        $this->assertSame([['year' => 2026, 'month' => 8, 'count' => 1]], app(ListHomeIssueMonths::class)());
    }

    public function test_a_site_that_has_published_nothing_has_no_months(): void
    {
        $this->assertSame([], app(ListHomeIssueMonths::class)());
    }

    private function publishOn(string $date): void
    {
        $day = CarbonImmutable::parse($date);

        HomeIssue::factory()->create([
            'issue_date' => $day->toDateString(),
            'window_start' => $day->addHours(6),
            'published_at' => $day->addDay()->addHours(6),
        ]);
    }
}
