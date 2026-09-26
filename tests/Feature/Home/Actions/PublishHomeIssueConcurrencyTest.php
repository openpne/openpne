<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Database\QueryException;

class PublishHomeIssueConcurrencyTest extends PublishHomeIssueTestCase
{
    public function test_a_second_run_on_the_same_day_returns_the_issue_and_writes_nothing(): void
    {
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $first = $this->publish();
        $this->assertNotNull($first);
        $items = HomeIssueItem::count();

        // A new story arriving between the two runs must not sneak into a published issue.
        $this->at($this->now()->subMinute(), fn (): TimelinePost => TimelinePost::factory()->create());

        $second = $this->publish();

        $this->assertNotNull($second);
        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('home_issues', 1);
        $this->assertSame($items, HomeIssueItem::count());
    }

    public function test_an_issue_already_in_the_table_is_never_rebuilt(): void
    {
        // Published two hours early by hand, and so already holding the day this run would date to.
        $existing = $this->previousIssue($this->now()->subHours(2), number: 41);
        $this->at($this->now()->subMinutes(30), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertTrue($existing->is($issue));
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_an_issue_inserted_underneath_the_run_is_reported_not_duplicated(): void
    {
        $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        $this->raceInARivalIssue();

        $issue = $this->publish();

        $this->assertTrue($this->raced, 'the rival insert has to have interleaved for this test to mean anything');
        $this->assertNotNull($issue);
        $this->assertSame(999, $issue->number);
        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_a_write_that_fails_on_anything_but_the_unique_still_reports_the_winner(): void
    {
        $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        $this->concurrencyDetectionOff();
        $this->raceInARivalIssue(fn () => $this->failTheWrite());

        $issue = $this->publish();

        $this->assertTrue($this->raced, 'the rival insert has to have interleaved for this test to mean anything');
        $this->assertNotNull($issue);
        $this->assertSame(999, $issue->number);
        $this->assertDatabaseCount('home_issues', 1);
        $this->assertDatabaseCount('home_issue_items', 0);
    }

    public function test_a_failed_write_with_no_issue_for_the_day_stays_loud(): void
    {
        $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        $this->concurrencyDetectionOff();
        $this->failTheWrite();

        try {
            $this->publish();
            $this->fail('a failed write was swallowed with no issue to report');
        } catch (QueryException $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());
        }

        $this->assertDatabaseCount('home_issues', 0);
        $this->assertDatabaseCount('home_issue_items', 0);
    }
}
