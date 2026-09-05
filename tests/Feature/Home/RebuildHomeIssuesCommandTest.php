<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\HomeIssue;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RebuildHomeIssuesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** 06:00 on the 27th: the 26th has just closed and is the latest day a rebuild reaches. */
    private const NOW = '2026-08-27 06:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
    }

    /**
     * Days 23 and 25 were published, 24 and 26 were blank; a story turning up inside the 24th
     * afterwards gets its issue, numbered where its date falls.
     */
    public function test_it_republishes_every_day_from_the_archives_first_day(): void
    {
        $this->archive();
        $before = HomeIssue::query()->pluck('id')->all();

        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));

        $this->artisan('openpne:rebuild-home-issues')
            ->expectsOutputToContain('Dropped 2 issues dated 2026-08-23 – 2026-08-25.')
            ->expectsOutputToContain('Published issue 2026-08-23 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->expectsOutputToContain('Published issue 2026-08-24 (No. 2): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->expectsOutputToContain('Published issue 2026-08-25 (No. 3): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->expectsOutputToContain('No issue for 2026-08-26: nothing qualified since 2026-08-26 06:00:00.')
            ->expectsOutputToContain('Rebuilt 2026-08-23 – 2026-08-26: 3 issues, 1 blank days.')
            ->assertSuccessful();

        $this->assertSame(
            ['2026-08-23' => 1, '2026-08-24' => 2, '2026-08-25' => 3],
            $this->numbersByDate(),
        );
        $this->assertEmpty(array_intersect($before, HomeIssue::query()->pluck('id')->all()), 'a dropped issue survived');
        // The old ledger rows went with their issues, not just the issues.
        $this->assertDatabaseCount('home_issue_items', 3);

        $rebuilt = HomeIssue::query()->whereDate('issue_date', '2026-08-24')->firstOrFail();
        $this->assertSame('2026-08-24 06:00:00', $rebuilt->window_start->toDateTimeString());
        $this->assertSame('2026-08-25 06:00:00', $rebuilt->published_at->toDateTimeString());
    }

    public function test_from_keeps_the_days_before_it_and_numbers_on_from_them(): void
    {
        $this->archive();
        $kept = HomeIssue::query()->whereDate('issue_date', '2026-08-23')->firstOrFail();

        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-25')
            ->expectsOutputToContain('Dropped 1 issues dated 2026-08-25 – 2026-08-25.')
            ->expectsOutputToContain('Published issue 2026-08-25 (No. 2)')
            ->expectsOutputToContain('Rebuilt 2026-08-25 – 2026-08-26: 1 issues, 1 blank days.')
            ->assertSuccessful();

        $this->assertSame(['2026-08-23' => 1, '2026-08-25' => 2], $this->numbersByDate());
        $this->assertTrue($kept->is(HomeIssue::query()->whereDate('issue_date', '2026-08-23')->firstOrFail()));
        // The 24th lies before --from, so the story that turned up in it is not reported.
        $this->assertDatabaseCount('home_issue_items', 2);
    }

    /**
     * Consecutive issues share their boundary instant, so a --from that opens on one does not rebuild
     * the issue that closed on it.
     */
    public function test_from_the_day_after_a_kept_issue_leaves_it_whole(): void
    {
        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));
        $this->story(CarbonImmutable::parse('2026-08-26 03:00:00'));
        $this->story(CarbonImmutable::parse('2026-08-26 12:00:00'));

        foreach (['2026-08-24', '2026-08-25', '2026-08-26'] as $date) {
            $this->artisan("openpne:publish-home-issue --date={$date}")->assertSuccessful();
        }

        $kept = HomeIssue::query()->whereDate('issue_date', '2026-08-24')->firstOrFail();

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-25')
            ->expectsOutputToContain('Dropped 2 issues dated 2026-08-25 – 2026-08-26.')
            ->expectsOutputToContain('Rebuilt 2026-08-25 – 2026-08-26: 2 issues, 0 blank days.')
            ->assertSuccessful();

        $this->assertTrue($kept->is(HomeIssue::query()->whereDate('issue_date', '2026-08-24')->firstOrFail()));
        $this->assertSame(['2026-08-24' => 1, '2026-08-25' => 2, '2026-08-26' => 3], $this->numbersByDate());

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-26')
            ->expectsOutputToContain('Dropped 1 issues dated 2026-08-26 – 2026-08-26.')
            ->expectsOutputToContain('Rebuilt 2026-08-26 – 2026-08-26: 1 issues, 0 blank days.')
            ->assertSuccessful();

        $this->assertSame(['2026-08-24' => 1, '2026-08-25' => 2, '2026-08-26' => 3], $this->numbersByDate());
        $this->assertDatabaseCount('home_issue_items', 3);
    }

    /**
     * The scheduled run lands a second or two past 06:00, and the archive it writes must still take
     * a --from: its windows close on the boundary, not on the clock (PublishHomeIssue::window).
     */
    public function test_an_archive_the_schedule_wrote_late_still_takes_a_from(): void
    {
        $this->story(CarbonImmutable::parse('2026-08-24 12:00:00'));
        $this->story(CarbonImmutable::parse('2026-08-25 12:00:00'));
        $this->story(CarbonImmutable::parse('2026-08-26 12:00:00'));

        foreach (['2026-08-25', '2026-08-26', '2026-08-27'] as $morning) {
            Carbon::setTestNow("{$morning} 06:00:02");
            $this->artisan('openpne:publish-home-issue')->expectsOutputToContain('Published issue')->assertSuccessful();
        }

        Carbon::setTestNow('2026-08-27 06:00:02');
        $this->assertSame(['2026-08-24' => 1, '2026-08-25' => 2, '2026-08-26' => 3], $this->numbersByDate());

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-26')
            ->expectsOutputToContain('Dropped 1 issues dated 2026-08-26 – 2026-08-26.')
            ->expectsOutputToContain('Published issue 2026-08-26 (No. 3): 1 stories')
            ->assertSuccessful();

        $this->assertSame(['2026-08-24' => 1, '2026-08-25' => 2, '2026-08-26' => 3], $this->numbersByDate());
    }

    /** A rebuilt day's calendar looks forward from its own morning, not from the day of the rebuild. */
    public function test_a_rebuilt_day_lists_the_events_upcoming_from_its_own_morning(): void
    {
        $this->archive();

        Carbon::setTestNow(CarbonImmutable::parse(self::NOW)->subDays(30));
        GroupEvent::factory()->for(Group::factory())->create(['open_date' => '2026-08-25']);
        Carbon::setTestNow(self::NOW);

        $this->artisan('openpne:rebuild-home-issues')
            ->expectsOutputToContain('Published issue 2026-08-23 (No. 1): 1 stories, 0 talk, 0 newcomers, 0 new groups, 1 upcoming events.')
            ->expectsOutputToContain('Published issue 2026-08-25 (No. 2): 1 stories, 0 talk, 0 newcomers, 0 new groups, 0 upcoming events.')
            ->assertSuccessful();
    }

    /** One transaction end to end: a write that fails halfway leaves the archive as it was. */
    public function test_a_failed_write_leaves_the_archive_as_it_was(): void
    {
        $this->archive();
        $before = HomeIssue::query()->pluck('number', 'id')->all();
        $level = DB::transactionLevel();

        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));
        HomeIssue::creating(function (HomeIssue $issue): void {
            if ($issue->issue_date->toDateString() === '2026-08-25') {
                throw new RuntimeException('the 25th will not write');
            }
        });

        try {
            $this->artisan('openpne:rebuild-home-issues')->run();
            $this->fail('the failed write did not surface');
        } catch (RuntimeException $e) {
            $this->assertSame('the 25th will not write', $e->getMessage());
        }

        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, HomeIssue::query()->pluck('number', 'id')->all());
        $this->assertDatabaseCount('home_issue_items', 2);
    }

    /** The dry run is the rebuild itself, rolled back: what it reports is what a real run would write. */
    public function test_a_dry_run_reports_the_rebuild_and_keeps_the_archive(): void
    {
        $this->archive();
        $before = HomeIssue::query()->pluck('number', 'id')->all();

        $this->story(CarbonImmutable::parse('2026-08-25 03:00:00'));

        $this->artisan('openpne:rebuild-home-issues --dry-run')
            ->expectsOutputToContain('Would drop 2 issues dated 2026-08-23 – 2026-08-25.')
            ->expectsOutputToContain('Would publish issue 2026-08-24 (No. 2): 1 stories')
            ->expectsOutputToContain('Would publish issue 2026-08-25 (No. 3): 1 stories')
            ->expectsOutputToContain('Dry run of 2026-08-23 – 2026-08-26: 3 issues, 1 blank days. Nothing was written.')
            ->assertSuccessful();

        $this->assertSame($before, HomeIssue::query()->pluck('number', 'id')->all());
        $this->assertDatabaseCount('home_issue_items', 2);
    }

    /**
     * A day inside the first issue's week cannot start a rebuild — dropping the issue would lose the
     * days before it, keeping it would report the days after it twice — but the day it opens on can.
     */
    public function test_a_from_inside_an_issues_window_is_refused_and_its_first_day_is_not(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-22')
            ->expectsOutputToContain('Issue 2026-08-26 (No. 1) covers 2026-08-20 06:00:00 – 2026-08-27 06:00:00, which reaches back past 2026-08-22; rebuild from 2026-08-20 instead.')
            ->assertFailed();

        $this->assertDatabaseCount('home_issues', 1);

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-20')
            ->expectsOutputToContain('Dropped 1 issues dated 2026-08-26 – 2026-08-26.')
            ->expectsOutputToContain('Published issue 2026-08-26 (No. 1)')
            ->expectsOutputToContain('Rebuilt 2026-08-20 – 2026-08-26: 1 issues, 6 blank days.')
            ->assertSuccessful();

        $issue = HomeIssue::query()->firstOrFail();
        $this->assertSame('2026-08-26 06:00:00', $issue->window_start->toDateTimeString());
    }

    /** Without --from the rebuild starts where that first issue's window opens, whole days only. */
    public function test_the_default_first_day_is_the_one_the_earliest_window_opens_in(): void
    {
        $this->story();
        $this->artisan('openpne:publish-home-issue')->assertSuccessful();

        $this->artisan('openpne:rebuild-home-issues --dry-run')
            ->expectsOutputToContain('Dry run of 2026-08-20 – 2026-08-26: 1 issues, 6 blank days.')
            ->assertSuccessful();
    }

    public function test_a_day_that_is_not_over_is_refused(): void
    {
        $this->archive();

        foreach (['2026-08-27' => '2026-08-28 06:00:00', '2026-09-01' => '2026-09-02 06:00:00'] as $date => $ends) {
            $this->artisan("openpne:rebuild-home-issues --from={$date}")
                ->expectsOutputToContain("Day {$date} is not over yet: it runs to {$ends}.")
                ->assertFailed();
        }

        $this->assertDatabaseCount('home_issues', 2);
    }

    public function test_an_empty_archive_needs_a_from(): void
    {
        $this->artisan('openpne:rebuild-home-issues')
            ->expectsOutputToContain('Nothing to rebuild: no issue is published.')
            ->assertFailed();

        $this->story(CarbonImmutable::parse('2026-08-26 03:00:00'));

        $this->artisan('openpne:rebuild-home-issues --from=2026-08-25')
            ->expectsOutputToContain('Dropped 0 issues.')
            ->expectsOutputToContain('Published issue 2026-08-25 (No. 1)')
            ->assertSuccessful();

        $this->assertDatabaseCount('home_issues', 1);
    }

    public function test_a_date_that_is_not_one_is_refused(): void
    {
        foreach (['yesterday', '2026-8-24', '2026-02-30', ''] as $date) {
            $this->artisan('openpne:rebuild-home-issues', ['--from' => $date])
                ->expectsOutputToContain('--from must name a day on the calendar as YYYY-MM-DD')
                ->assertFailed();
        }
    }

    /** Issues for the 23rd and 25th, each from its own window; the 24th and 26th had nothing. */
    private function archive(): void
    {
        $this->story(CarbonImmutable::parse('2026-08-24 03:00:00'));
        $this->story(CarbonImmutable::parse('2026-08-26 03:00:00'));

        foreach (['2026-08-23', '2026-08-24', '2026-08-25', '2026-08-26'] as $date) {
            $this->artisan("openpne:publish-home-issue --date={$date}")->assertSuccessful();
        }

        $this->assertSame(['2026-08-23' => 1, '2026-08-25' => 2], $this->numbersByDate());
    }

    /** @return array<string, int> */
    private function numbersByDate(): array
    {
        return HomeIssue::query()
            ->orderBy('issue_date')
            ->get()
            ->mapWithKeys(fn (HomeIssue $issue): array => [$issue->issue_date->toDateString() => $issue->number])
            ->all();
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
