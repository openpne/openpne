<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\Actions\PublishDueHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Models\GroupEvent;
use App\Models\HomeIssue;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * The front page on a host with no scheduler: the clock is half an hour past the 06:00 boundary and
 * no run has happened (docs/internals/home-issues.md, "Publish on read").
 */
class PublishOnReadTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:30:00';

    private const BOUNDARY = '2026-08-27 06:00:00';

    private Member $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        // Old enough not to be a newcomer in any window below: the reader must not become a story.
        Carbon::setTestNow(CarbonImmutable::parse(self::NOW)->subDays(60));
        $this->viewer = Member::factory()->create();

        Carbon::setTestNow(self::NOW);
        config(['openpne.surface_mode' => 'modern_only']);
    }

    public function test_the_first_visit_after_the_boundary_publishes_what_the_schedule_would_have(): void
    {
        $this->story(CarbonImmutable::parse(self::BOUNDARY)->subHour());

        $this->visit()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home/issue')
                ->where('issue.number', 1)
                ->where('issue.isCurrent', true));

        $issue = HomeIssue::sole();
        // Dated and closed on the boundary, not on the clock the visit happened to arrive at.
        $this->assertSame('2026-08-26', $issue->issue_date->toDateString());
        $this->assertSame(self::BOUNDARY, $issue->published_at->toDateTimeString());
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    /** The 26th's issue is out and the 27th's window is open with a story in it: only the clock holds it. */
    public function test_a_visit_before_the_boundary_publishes_nothing(): void
    {
        $previous = $this->publishedOn('2026-08-25');
        $this->story(CarbonImmutable::parse('2026-08-26 12:00:00'));
        Carbon::setTestNow('2026-08-27 05:59:00');

        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.number', (int) $previous->number));

        $this->assertDatabaseCount('home_issues', 1);
    }

    public function test_a_boundary_the_schedule_already_published_is_left_alone(): void
    {
        $this->publishedOn('2026-08-26');
        $this->story(CarbonImmutable::parse(self::BOUNDARY)->subHour());

        $this->visit();

        $this->assertDatabaseCount('home_issues', 1);
        // Left alone means no attempt either: the row is what answers, not a spent key.
        $this->assertFalse(Cache::has('home-issue:attempted:2026-08-27'));
    }

    /**
     * A blank day leaves no row, so the attempt itself is what must not repeat: a story backdated
     * into the window after the visit stays out until the next boundary, as it would have under the
     * 06:00 run.
     */
    public function test_a_blank_day_is_planned_once_per_boundary(): void
    {
        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue', null));

        $this->story(CarbonImmutable::parse(self::BOUNDARY)->subHour());
        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue', null));
        $this->assertDatabaseCount('home_issues', 0);

        // Inside the first attempt's 24 hours, so only a key named for the new boundary lets this run.
        Carbon::setTestNow('2026-08-28 06:10:00');
        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.number', 1));

        $issue = HomeIssue::sole();
        $this->assertSame('2026-08-27', $issue->issue_date->toDateString());
        // The first issue ever reaches back a week, so the story the spent attempt missed is in it.
        $this->assertSame('2026-08-21 06:00:00', $issue->window_start->toDateTimeString());
        $this->assertDatabaseCount('home_issue_items', 1);
    }

    /** An event on the boundary day is upcoming in the issue although it is yesterday for a 05:00 reader. */
    public function test_the_issue_is_published_as_of_the_boundary_not_the_clock(): void
    {
        Carbon::setTestNow('2026-08-28 05:00:00');
        $this->story(CarbonImmutable::parse(self::BOUNDARY)->subHour());
        GroupEvent::factory()->create(['open_date' => '2026-08-27']);

        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('issue.date', '2026-08-26')
            ->where('issue.upcomingEvents.0.openDate', '2026-08-27'));
    }

    public function test_a_failed_publication_is_reported_and_the_page_still_renders(): void
    {
        Exceptions::fake();
        $previous = $this->publishedOn('2026-08-25');
        $this->story(CarbonImmutable::parse(self::BOUNDARY)->subHour());
        $fail = true;
        DB::beforeExecuting(function (string $query) use (&$fail): void {
            if ($fail && str_contains($query, 'insert into') && str_contains($query, 'home_issues')) {
                throw new RuntimeException('the write failed');
            }
        });

        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.number', (int) $previous->number));

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'the write failed');
        $this->assertDatabaseCount('home_issues', 1);

        // The failure holds the boundary for ten minutes, then the next read tries again.
        $fail = false;
        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.number', (int) $previous->number));
        $this->assertDatabaseCount('home_issues', 1);

        Carbon::setTestNow(CarbonImmutable::parse(self::NOW)->addSeconds(PublishDueHomeIssue::RETRY_SECONDS + 1));
        $this->visit()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.isCurrent', true));
        $this->assertDatabaseCount('home_issues', 2);
    }

    private function visit(): TestResponse
    {
        return $this->actingAs($this->viewer)->get('/')->assertOk();
    }

    /** One issue covering the day $date, closed on its own boundary. */
    private function publishedOn(string $date): HomeIssue
    {
        $window = HomeIssueDay::window(CarbonImmutable::parse($date));

        return HomeIssue::factory()->create([
            'issue_date' => $date,
            'window_start' => $window->start,
            'published_at' => $window->end,
        ]);
    }

    /** A timeline post written at $at by a member old enough not to count as a newcomer. */
    private function story(CarbonImmutable $at): void
    {
        $now = Carbon::getTestNow();

        Carbon::setTestNow($at->subDays(30));
        $author = Member::factory()->create();

        Carbon::setTestNow($at);
        TimelinePost::factory()->for($author)->create();

        Carbon::setTestNow($now);
    }
}
