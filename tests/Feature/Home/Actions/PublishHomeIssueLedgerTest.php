<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\HomeIssueItem;
use App\Models\TimelinePost;

class PublishHomeIssueLedgerTest extends PublishHomeIssueTestCase
{
    public function test_stats_are_frozen_at_publication(): void
    {
        $diary = $this->at($this->now()->subHour(), function (): Diary {
            $diary = Diary::factory()->create();
            DiaryComment::factory()->for($diary)->create();

            return $diary;
        });

        $issue = $this->publish();
        $this->assertNotNull($issue);

        DiaryComment::factory()->count(3)->for($diary)->create();

        $item = $this->item($issue->fresh(), HomeIssueSection::Stories, $this->ref($diary));
        $this->assertSame(1, $item->score);
        // assertEquals, not assertSame: MySQL's JSON type normalizes an object's key order and
        // SQLite keeps the text as written, so the key set is the contract and the order is not.
        $this->assertEquals(['comments' => 1, 'images' => 0], $item->stats);
    }

    public function test_every_source_kind_freezes_its_own_stats(): void
    {
        $this->at($this->now()->subHours(3), function (): void {
            $post = TimelinePost::factory()->create();
            TimelinePost::factory()->count(2)->replyTo($post)->create();

            $topic = GroupTopic::factory()->create();
            GroupTopicComment::factory()->for($topic, 'topic')->create();

            $event = GroupEvent::factory()->create(['open_date' => $this->now()->addDays(2)]);
            GroupEventMember::factory()->count(3)->for($event, 'event')->create();

            $group = Group::factory()->create();
            GroupMessage::factory()->count(3)->for($group)->create();
        });

        $issue = $this->publish();
        $this->assertNotNull($issue);

        $stats = $issue->items->mapWithKeys(fn (HomeIssueItem $item): array => [
            $item->section->value.'/'.$item->source_type => array_keys($item->stats),
        ]);

        // Canonicalizing throughout: MySQL's JSON type reorders an object's keys on the way in.
        $this->assertEqualsCanonicalizing(['replies'], $stats['stories/timelinePost']);
        $this->assertEqualsCanonicalizing(['comments'], $stats['stories/groupTopic']);
        $this->assertEqualsCanonicalizing(['comments', 'participants'], $stats['stories/groupEvent']);
        $this->assertEqualsCanonicalizing(['messages', 'authors', 'reactions', 'since', 'until'], $stats['talk/group']);
        $this->assertEqualsCanonicalizing([], $stats['newcomers/member']);
        $this->assertEqualsCanonicalizing(['members'], $stats['new_groups/group']);
        $this->assertEqualsCanonicalizing(['comments', 'participants'], $stats['upcoming_events/groupEvent']);
    }

    public function test_the_number_counts_issues_not_days(): void
    {
        $this->at($this->now()->subDays(3), fn (): TimelinePost => TimelinePost::factory()->create());
        $first = $this->publish($this->now()->subDays(2)->setTime(6, 0));

        // Nothing happens on the day between, so no issue takes a number.
        $this->assertNull($this->publish($this->now()->subDay()->setTime(6, 0)));

        $this->at($this->now()->subHours(2), fn (): TimelinePost => TimelinePost::factory()->create());
        $second = $this->publish();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
    }
}
