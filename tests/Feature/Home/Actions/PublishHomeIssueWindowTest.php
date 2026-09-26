<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\HomeIssueSection;
use App\Models\GroupEvent;
use App\Models\TimelinePost;
use Carbon\CarbonImmutable;

class PublishHomeIssueWindowTest extends PublishHomeIssueTestCase
{
    // --- which day an issue is ---

    public function test_the_scheduled_run_dates_the_issue_to_the_day_that_just_ended(): void
    {
        $this->previousIssue($this->now()->subDay());
        $this->at($this->now()->subHours(8), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame('2026-08-26', $issue->issue_date->toDateString());
        $this->assertTrue($this->now()->equalTo($issue->published_at), 'published_at is not the window end');
    }

    public function test_a_run_by_hand_publishes_the_day_that_closed_this_morning(): void
    {
        $this->previousIssue($this->now()->subDay());
        $overnight = $this->at($this->now()->subHours(8), fn (): TimelinePost => TimelinePost::factory()->create());
        $afternoon = $this->now()->addHours(9);
        $today = $this->at($afternoon->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $byHand = $this->publish($afternoon);

        $this->assertNotNull($byHand);
        $this->assertSame('2026-08-26', $byHand->issue_date->toDateString());
        $this->assertTrue($this->now()->equalTo($byHand->published_at), 'published_at is not this morning\'s boundary');
        $this->assertSame([$this->ref($overnight)], $this->refs($byHand, HomeIssueSection::Stories));

        // A second run by hand the same day finds the day published and writes nothing.
        $this->assertTrue($byHand->is($this->publish($afternoon->addHour())));
        $this->assertDatabaseCount('home_issues', 2);

        // The next morning's run takes the afternoon's post with the rest of the day.
        $next = $this->publish($this->now()->addDay());

        $this->assertNotNull($next);
        $this->assertSame('2026-08-27', $next->issue_date->toDateString());
        $this->assertTrue($this->now()->equalTo($next->window_start));
        $this->assertSame([$this->ref($today)], $this->refs($next, HomeIssueSection::Stories));
    }

    /**
     * Closed on the clock, this issue would be dated the day it went out and the next window would
     * open a second late.
     */
    public function test_a_scheduled_run_a_second_late_closes_on_the_boundary(): void
    {
        $this->previousIssue($this->now()->subDay());
        $overnight = $this->at($this->now()->subHours(8), fn (): TimelinePost => TimelinePost::factory()->create());
        $onTheSecond = $this->at($this->now()->addSecond(), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish($this->now()->addSeconds(2));

        $this->assertNotNull($issue);
        $this->assertSame('2026-08-26', $issue->issue_date->toDateString());
        $this->assertTrue($this->now()->equalTo($issue->published_at));
        $this->assertSame([$this->ref($overnight)], $this->refs($issue, HomeIssueSection::Stories));
        $this->assertNotContains($this->ref($onTheSecond), $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_late_first_issue_reaches_back_from_the_boundary(): void
    {
        $this->at($this->now()->subDays(3), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish($this->now()->addSeconds(2));

        $this->assertNotNull($issue);
        $this->assertTrue($this->now()->subDays(7)->equalTo($issue->window_start));
        $this->assertTrue($this->now()->equalTo($issue->published_at));
    }

    /** A window that spans days is dated by the last of them, seven-day first issue included. */
    public function test_the_first_issue_is_dated_by_the_last_day_it_covers(): void
    {
        $this->at($this->now()->subDays(3), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame('2026-08-26', $issue->issue_date->toDateString());
        // Seven days back, so the stretch opens on the 20th's day and closes on the 26th's.
        $this->assertSame('2026-08-20', $issue->window_start->toDateString());
    }

    /** A given window fixes both bounds and the date, whatever the clock says — this is `--date`. */
    public function test_an_explicit_window_fixes_both_bounds_and_the_date(): void
    {
        $day = CarbonImmutable::parse('2026-08-20');
        $window = HomeIssueDay::window($day);
        $this->previousIssue($this->now());
        $inside = $this->at($day->setTime(15, 0), fn (): TimelinePost => TimelinePost::factory()->create());
        $this->at($day->subDay()->setTime(15, 0), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish(window: $window);

        $this->assertNotNull($issue);
        $this->assertSame('2026-08-20', $issue->issue_date->toDateString());
        $this->assertTrue($window->start->equalTo($issue->window_start));
        $this->assertTrue($window->end->equalTo($issue->published_at));
        $this->assertSame([$this->ref($inside)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_the_first_issue_reaches_back_seven_days(): void
    {
        $stale = $this->at($this->now()->subDays(8), fn (): TimelinePost => TimelinePost::factory()->create());
        $fresh = $this->at($this->now()->subDays(6), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertTrue($this->now()->subDays(7)->equalTo($issue->window_start));
        $this->assertSame([$this->ref($fresh)], $this->refs($issue, HomeIssueSection::Stories));
        $this->assertNotContains($this->ref($stale), $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_the_window_opens_at_the_previous_issues_published_at(): void
    {
        // The boundary instant belongs to the issue that closed on it, so it must not be reported
        // twice; everything after it, up to and including this issue's own instant, is new.
        $previous = $this->now()->subDay();
        $this->previousIssue($previous);

        $onTheBoundary = $this->at($previous, fn (): TimelinePost => TimelinePost::factory()->create());
        $justAfter = $this->at($previous->addSecond(), fn (): TimelinePost => TimelinePost::factory()->create());
        $atPublish = $this->at($this->now(), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertTrue($previous->equalTo($issue->window_start));
        $this->assertEqualsCanonicalizing(
            [$this->ref($justAfter), $this->ref($atPublish)],
            $this->refs($issue, HomeIssueSection::Stories),
        );
        $this->assertNotContains($this->ref($onTheBoundary), $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_blank_day_writes_nothing_and_the_next_window_spans_the_gap(): void
    {
        $previous = $this->now()->subDays(2);
        $this->previousIssue($previous);

        // Nothing at all happened on the day between; the story arrives after it.
        $this->assertNull($this->publish($this->now()->subDay()->setTime(6, 0)));
        $this->assertDatabaseCount('home_issues', 1);

        $post = $this->at($this->now()->subHours(12), fn (): TimelinePost => TimelinePost::factory()->create());

        // Wrong here would be a window that starts at the day nothing came out.
        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertTrue($previous->equalTo($issue->window_start));
        $this->assertSame([$this->ref($post)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_blank_day_is_a_blank_plan_not_an_empty_issue(): void
    {
        $this->assertNull($this->action()->plan($this->now()));
        $this->assertNull($this->publish());
        $this->assertDatabaseCount('home_issues', 0);
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_upcoming_events_alone_do_not_trigger_an_issue(): void
    {
        // A calendar repeats itself by design, so an issue it could trigger would come out every day
        // of a quiet month saying the same thing.
        $this->at($this->now()->subDays(30), fn (): GroupEvent => GroupEvent::factory()->create([
            'open_date' => $this->now()->addDays(2),
        ]));

        $this->assertNull($this->publish());
        $this->assertDatabaseCount('home_issues', 0);
    }
}
