<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\Actions\PublishHomeIssue;
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

        // The command reads the clock itself — it is the one thing about the issue nothing may pass
        // in, since a back-dated issue would claim a stretch the one after it already reported.
        Carbon::setTestNow(self::NOW);
    }

    public function test_it_runs_once_a_day_on_the_site_clock(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'openpne:publish-home-issue'));

        $this->assertCount(1, $events, 'the publisher is not registered on the schedule');
        $this->assertSame('0 6 * * *', $events->first()->expression);
        $this->assertSame('06:00', PublishHomeIssue::TIME);
        // Foreground: capped reads and one insert, so it does not hold `schedule:run` past the next
        // minute's tick the way the link-card sweep can.
        $this->assertFalse($events->first()->runInBackground);
    }

    public function test_it_publishes_and_reports_what_the_issue_holds(): void
    {
        $this->story();

        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('Published issue 2026-08-27 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    public function test_a_dry_run_reports_the_same_issue_and_writes_nothing(): void
    {
        $this->story();

        $this->artisan('openpne:publish-home-issue --dry-run')
            ->expectsOutputToContain('Would publish issue 2026-08-27 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 0);
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_a_second_run_the_same_day_says_so_and_adds_nothing(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('Issue 2026-08-27 is already published (No. 1).')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    public function test_it_says_when_nothing_qualified(): void
    {
        $this->artisan('openpne:publish-home-issue')
            ->expectsOutputToContain('No issue today: nothing qualified since 2026-08-20 06:00:00.')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 0);
    }

    /** One story and nothing else: an author from before the window is not also a newcomer. */
    private function story(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        Carbon::setTestNow($now->subDays(30));
        $author = Member::factory()->create();

        Carbon::setTestNow($now->subHour());
        TimelinePost::factory()->for($author)->create();

        Carbon::setTestNow($now);
    }
}
