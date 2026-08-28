<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Models\HomeIssue;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishHomeIssueCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // The command reads the clock itself. What it may be told is which past day to report;
        // where the present one ends is the schedule's to decide.
        Carbon::setTestNow(self::NOW);
    }

    public function test_it_runs_once_a_day_on_the_site_clock(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'openpne:publish-home-issue'));

        $this->assertCount(1, $events, 'the publisher is not registered on the schedule');
        $this->assertSame('0 6 * * *', $events->first()->expression);
        $this->assertSame('06:00', PublishHomeIssue::TIME);
        // The schedule needs the hour as a string and the day rule needs it as a number; drift
        // between them would move the boundary for one and not the other.
        $this->assertSame(sprintf('%02d:00', HomeIssueDay::HOUR), PublishHomeIssue::TIME);
        // Foreground: capped reads and one insert, so it does not hold `schedule:run` past the next
        // minute's tick the way the link-card sweep can.
        $this->assertFalse($events->first()->runInBackground);
    }

    /** The 06:00 run reports the day that just ended, and says which day that was. */
    public function test_it_publishes_and_reports_what_the_issue_holds(): void
    {
        $this->story();

        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('Published issue 2026-08-26 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    public function test_a_dry_run_reports_the_same_issue_and_writes_nothing(): void
    {
        $this->story();

        $this->artisan('openpne:publish-home-issue --dry-run')
            ->expectsOutputToContain('Would publish issue 2026-08-26 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 0);
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_a_second_run_the_same_day_says_so_and_adds_nothing(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('Issue 2026-08-26 is already published (No. 1).')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    /** Named by the day it would have covered: "today" is not that date and never was. */
    public function test_it_says_when_nothing_qualified(): void
    {
        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('No issue for 2026-08-26: nothing qualified since 2026-08-20 06:00:00.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 0);
    }

    // --- filling in a past day ---

    public function test_a_named_day_is_published_from_its_own_window(): void
    {
        // Inside the 24th's day, which runs to 06:00 on the 25th, and so outside the 25th's.
        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));

        $this->artisan('openpne:publish-home-issue --date=2026-08-24')
            ->expectsOutputToContain('Published issue 2026-08-24 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $issue = HomeIssue::query()->firstOrFail();

        $this->assertSame('2026-08-24 06:00:00', $issue->window_start->toDateTimeString());
        $this->assertSame('2026-08-25 06:00:00', $issue->published_at->toDateTimeString());

        $this->artisan('openpne:publish-home-issue --date=2026-08-25')
            ->expectsOutputToContain('No issue for 2026-08-25: nothing qualified since 2026-08-25 06:00:00.')
            ->assertSuccessful();
    }

    /** The day that has just ended is a past day: its window closed on the instant of this run. */
    public function test_the_day_that_just_ended_may_be_named(): void
    {
        $this->story();

        $this->artisan('openpne:publish-home-issue --date=2026-08-26')
            ->expectsOutputToContain('Published issue 2026-08-26 (No. 1)')
            ->assertSuccessful();
    }

    public function test_a_day_that_is_not_over_is_refused(): void
    {
        foreach (['2026-08-27' => '2026-08-28 06:00:00', '2026-09-01' => '2026-09-02 06:00:00'] as $date => $ends) {
            $this->artisan("openpne:publish-home-issue --date={$date}")
                ->expectsOutputToContain("Issue {$date} is not over yet: its day runs to {$ends}.")
                ->assertFailed();
        }

        $this->assertDatabaseCount('home_issues', 0);
    }

    public function test_a_day_that_already_has_an_issue_is_refused(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:publish-home-issue --date=2026-08-26')
            ->expectsOutputToContain('Issue 2026-08-26 is already published (No. 1).')
            ->assertFailed();

        $this->assertDatabaseCount('home_issues', 1);
    }

    /**
     * A day the first issue already reached back over. Its own date is free — the unique on
     * `issue_date` would take it — but every happening in it has gone out once already.
     */
    public function test_a_day_inside_an_existing_issues_window_is_refused(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:publish-home-issue --date=2026-08-22')
            ->expectsOutputToContain('Issue 2026-08-22 overlaps issue 2026-08-26 (No. 1), which already covers 2026-08-20 06:00:00 – 2026-08-27 06:00:00.')
            ->assertFailed();

        $this->assertDatabaseCount('home_issues', 1);
    }

    /** The day before that reach ends where the first issue's window opens, and shares no instant. */
    public function test_a_day_before_an_existing_issues_window_is_published(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->story(CarbonImmutable::parse('2026-08-20 03:00:00'));

        $this->artisan('openpne:publish-home-issue --date=2026-08-19')
            ->expectsOutputToContain('Published issue 2026-08-19 (No. 2)')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 2);
    }

    /** A date constructor rolls February 30th into March; a publisher must not follow it there. */
    public function test_a_date_that_is_not_one_is_refused(): void
    {
        foreach (['yesterday', '2026-8-24', '2026-02-30', '24-08-2026', ''] as $date) {
            $this->artisan('openpne:publish-home-issue', ['--date' => $date])
                ->expectsOutputToContain('--date must name a day on the calendar as YYYY-MM-DD')
                ->assertFailed();
        }

        $this->assertDatabaseCount('home_issues', 0);
    }

    public function test_a_dry_run_of_a_named_day_writes_nothing(): void
    {
        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));

        $this->artisan('openpne:publish-home-issue --date=2026-08-24 --dry-run')
            ->expectsOutputToContain('Would publish issue 2026-08-24 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 0);
    }

    /** One story and nothing else: an author from before the window is not also a newcomer. */
    private function story(?CarbonImmutable $at = null): void
    {
        $now = CarbonImmutable::parse(self::NOW);
        $at ??= $now->subHour();

        Carbon::setTestNow($at->subDays(30));
        $author = Member::factory()->create();

        Carbon::setTestNow($at);
        TimelinePost::factory()->for($author)->create();

        Carbon::setTestNow($now);
    }
}
