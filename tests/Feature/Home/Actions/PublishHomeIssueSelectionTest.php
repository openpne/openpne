<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class PublishHomeIssueSelectionTest extends PublishHomeIssueTestCase
{
    /** @return array<string, array{0: string}> */
    public static function neverAgainKinds(): array
    {
        return [
            'timeline post' => ['timelinePost'],
            'diary' => ['diary'],
            'group topic' => ['groupTopic'],
            'group event' => ['groupEvent'],
            'newcomer' => ['member'],
            'new group' => ['group'],
        ];
    }

    #[DataProvider('neverAgainKinds')]
    public function test_a_source_a_section_has_featured_is_never_featured_again(string $kind): void
    {
        [$section, $featured, $fresh] = $this->at($this->now()->subDay(), fn (): array => match ($kind) {
            'timelinePost' => [HomeIssueSection::Stories, TimelinePost::factory()->create(), TimelinePost::factory()->create()],
            'diary' => [HomeIssueSection::Stories, Diary::factory()->create(), Diary::factory()->create()],
            'groupTopic' => [HomeIssueSection::Stories, GroupTopic::factory()->create(), GroupTopic::factory()->create()],
            'groupEvent' => [HomeIssueSection::Stories, GroupEvent::factory()->create(), GroupEvent::factory()->create()],
            'member' => [HomeIssueSection::Newcomers, Member::factory()->create(), Member::factory()->create()],
            'group' => [HomeIssueSection::NewGroups, Group::factory()->create(), Group::factory()->create()],
        });

        HomeIssueItem::factory()->forSource($featured)->create(['section' => $section]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, $section);
        $this->assertContains($this->ref($fresh), $refs);
        $this->assertNotContains($this->ref($featured), $refs);
    }

    public function test_a_talk_burst_recurs_however_often_it_has_been_featured(): void
    {
        // The item is the stretch, not the group, and next week's stretch is different news.
        $group = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());
        $this->burst($group, $this->now()->subHours(2));

        HomeIssueItem::factory()->forSource($group)->create(['section' => HomeIssueSection::Talk]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($group)], $this->refs($issue, HomeIssueSection::Talk));
    }

    public function test_an_upcoming_event_recurs_until_it_happens(): void
    {
        $event = $this->at($this->now()->subDays(30), fn (): GroupEvent => GroupEvent::factory()->create([
            'open_date' => $this->now()->addDays(3),
        ]));
        HomeIssueItem::factory()->forSource($event)->create(['section' => HomeIssueSection::UpcomingEvents]);

        // Something else has to carry the issue: the calendar never triggers one.
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($event)], $this->refs($issue, HomeIssueSection::UpcomingEvents));
    }

    public function test_the_never_again_memory_is_scoped_to_the_section(): void
    {
        // A group featured for being new is still news for what was said in it: the two bands ask
        // different questions about the same row.
        $group = $this->at($this->now()->subHours(3), fn (): Group => Group::factory()->create());
        HomeIssueItem::factory()->forSource($group)->create(['section' => HomeIssueSection::NewGroups]);
        $this->burst($group, $this->now()->subHours(2));

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($group)], $this->refs($issue, HomeIssueSection::Talk));
        $this->assertNotContains($this->ref($group), $this->refs($issue, HomeIssueSection::NewGroups));
    }

    public function test_every_section_stops_at_its_cap(): void
    {
        $this->at($this->now()->subDays(30), function (): void {
            // Older than the window, so the rooms that talk are not also new groups.
            foreach (Group::factory()->count(HomeIssueSection::Talk->cap() + 2)->create() as $group) {
                $this->burst($group, $this->now()->subHours(2));
            }

            GroupEvent::factory()->count(HomeIssueSection::UpcomingEvents->cap() + 2)->create([
                'open_date' => $this->now()->addDays(2),
            ]);
        });

        $this->at($this->now()->subHour(), function (): void {
            TimelinePost::factory()->count(HomeIssueSection::Stories->cap() + 2)->create();
            Group::factory()->count(HomeIssueSection::NewGroups->cap() + 2)->create();
            Member::factory()->count(HomeIssueSection::Newcomers->cap() + 2)->create();
        });

        $issue = $this->publish();

        $this->assertNotNull($issue);
        foreach (HomeIssueSection::cases() as $section) {
            $this->assertCount($section->cap(), $this->refs($issue, $section), "{$section->value} exceeded its cap");
        }
    }

    public function test_stories_rank_by_score_and_break_ties_on_the_newer_one(): void
    {
        $quiet = $this->at($this->now()->subHours(5), fn (): TimelinePost => $this->postWithReplies(2));
        $older = $this->at($this->now()->subHours(4), fn (): TimelinePost => $this->postWithReplies(5));
        $newer = $this->at($this->now()->subHours(3), fn (): TimelinePost => $this->postWithReplies(5));

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame(
            [$this->ref($newer), $this->ref($older), $this->ref($quiet)],
            $this->refs($issue, HomeIssueSection::Stories),
        );
    }

    public function test_a_score_tie_breaks_on_the_newer_story_even_when_it_carries_the_lower_id(): void
    {
        // Two kinds, because the tiebreak only decides anything in the merge, and the newer story is
        // given an explicit id — MySQL's counters run on across tests, so the sequence would make the
        // masking depend on what ran before.
        $older = $this->at($this->now()->subHours(3), fn (): TimelinePost => $this->postWithReplies(2));
        $newer = $this->at($this->now()->subHours(2), function () use ($older): Diary {
            $diary = Diary::factory()->create(['id' => max(1, (int) $older->getKey() - 1)]);
            DiaryComment::factory()->count(2)->for($diary)->create();

            return $diary;
        });

        $this->assertLessThanOrEqual(
            (int) $older->getKey(),
            (int) $newer->getKey(),
            'the newer story must not also be the higher id, or the tiebreak is masked',
        );

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($newer), $this->ref($older)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_stories_tied_on_score_and_instant_lead_with_the_higher_id(): void
    {
        [$first, $second] = $this->at($this->now()->subHours(2), fn (): array => [
            TimelinePost::factory()->create(),
            TimelinePost::factory()->create(),
        ]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($second), $this->ref($first)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_the_lead_is_rank_one_across_the_four_story_kinds(): void
    {
        $post = $this->at($this->now()->subHours(3), fn (): TimelinePost => $this->postWithReplies(1));
        $diary = $this->at($this->now()->subHours(2), function (): Diary {
            $diary = Diary::factory()->create();
            DiaryComment::factory()->count(4)->for($diary)->create();

            return $diary;
        });

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($diary), $this->ref($post)], $this->refs($issue, HomeIssueSection::Stories));
    }

    /** A single line is the day's news on a quiet site; a room nobody spoke in is not. */
    public function test_a_single_message_is_a_burst(): void
    {
        $silent = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());
        $talking = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());

        // Before the first window, which reaches back a week: a room last spoken in earlier than that.
        $this->burst($silent, $this->now()->subDays(10), 1);
        $this->burst($talking, $this->now()->subHour(), 1);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($talking)], $this->refs($issue, HomeIssueSection::Talk));
        // Equals, not same: MySQL hands JSON keys back in its own order.
        $this->assertEquals(
            ['messages' => 1, 'authors' => 1, 'reactions' => 0],
            array_intersect_key($issue->items->firstWhere('section', HomeIssueSection::Talk)->stats, array_flip(['messages', 'authors', 'reactions'])),
        );
    }

    public function test_a_bursts_score_is_messages_plus_authors_plus_reactions(): void
    {
        $group = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());

        $messages = $this->at($this->now()->subHour(), function () use ($group): array {
            $authors = Member::factory()->count(2)->create();

            return [
                GroupMessage::factory()->for($group)->create(['member_id' => $authors[0]->id]),
                GroupMessage::factory()->for($group)->create(['member_id' => $authors[1]->id]),
                GroupMessage::factory()->for($group)->create(['member_id' => $authors[0]->id]),
            ];
        });

        $this->react($messages[0], 2);
        $this->react($messages[2], 1);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $item = $this->item($issue, HomeIssueSection::Talk, $this->ref($group));
        $this->assertSame(3 + 2 + 3, $item->score);
        $this->assertSame(3, $item->stats['messages']);
        $this->assertSame(2, $item->stats['authors']);
        $this->assertSame(3, $item->stats['reactions']);
        $this->assertSame($this->now()->subDays(7)->toIso8601String(), $item->stats['since']);
        $this->assertSame($this->now()->toIso8601String(), $item->stats['until']);
    }

    public function test_the_talk_band_cuts_to_the_cap_by_score(): void
    {
        // Four rooms saying the same amount, one author each, so only reactions separate them — and
        // reactions run down as the ids run up, so neither the recency nor the id tiebreak can
        // produce this order on its own.
        $groups = $this->at($this->now()->subDays(30), fn (): array => Group::factory()->count(4)->create()->all());
        $author = $this->at($this->now()->subDays(30), fn (): Member => Member::factory()->create());

        foreach ($groups as $index => $group) {
            $messages = $this->at($this->now()->subHour(), fn (): array => GroupMessage::factory()
                ->count(3)
                ->for($group)
                ->create(['member_id' => $author->id])
                ->all());

            $this->react($messages[0], 3 - $index);
        }

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame(
            [$this->ref($groups[0]), $this->ref($groups[1]), $this->ref($groups[2])],
            $this->refs($issue, HomeIssueSection::Talk),
        );
    }

    public function test_a_reaction_on_a_message_outside_the_window_is_not_the_bursts(): void
    {
        $group = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());

        $old = $this->at($this->now()->subDays(30), fn (): GroupMessage => GroupMessage::factory()->for($group)->create());
        $this->react($old, 5);

        $this->burst($group, $this->now()->subHour(), 3);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame(0, $this->item($issue, HomeIssueSection::Talk, $this->ref($group))->stats['reactions']);
    }

    public function test_a_reply_is_not_a_story(): void
    {
        $this->at($this->now()->subHour(), function (): void {
            $parent = TimelinePost::factory()->create();
            TimelinePost::factory()->replyTo($parent)->create();
        });

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertCount(1, $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_post_not_every_member_may_read_is_not_a_story(): void
    {
        [$open, $friends, $private] = $this->at($this->now()->subHour(), fn (): array => [
            TimelinePost::factory()->create(),
            TimelinePost::factory()->friends()->create(),
            TimelinePost::factory()->private()->create(),
        ]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, HomeIssueSection::Stories);
        $this->assertContains($this->ref($open), $refs);
        $this->assertNotContains($this->ref($friends), $refs);
        $this->assertNotContains($this->ref($private), $refs);
    }

    public function test_a_diary_not_every_member_may_read_is_not_a_story(): void
    {
        [$open, $friends] = $this->at($this->now()->subHour(), fn (): array => [
            Diary::factory()->create(),
            Diary::factory()->friends()->create(),
        ]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, HomeIssueSection::Stories);
        $this->assertContains($this->ref($open), $refs);
        $this->assertNotContains($this->ref($friends), $refs);
    }

    public function test_a_members_only_groups_talk_board_and_calendar_stay_inside_it(): void
    {
        $group = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create([
            'topic_read_access' => TopicReadAccess::MembersOnly,
        ]));

        [$topic, $event] = $this->at($this->now()->subHour(), fn (): array => [
            GroupTopic::factory()->for($group)->create(),
            GroupEvent::factory()->for($group)->create(['open_date' => $this->now()->addDays(2)]),
        ]);
        $this->burst($group, $this->now()->subHour());

        // Something has to carry the plan for the absences to mean anything.
        $carrier = $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $plan = $this->action()->plan($this->now());

        $this->assertNotNull($plan);
        $this->assertSame([$this->ref($carrier)], $this->planned($plan, HomeIssueSection::Stories));
        $this->assertSame([], $this->planned($plan, HomeIssueSection::Talk));
        $this->assertSame([], $this->planned($plan, HomeIssueSection::UpcomingEvents));

        // Flipping the one column admits all three — read twice at the same instant, so the window is
        // held still and only the gate moves.
        $group->update(['topic_read_access' => TopicReadAccess::Everyone]);

        $next = $this->action()->plan($this->now());

        $this->assertNotNull($next);
        $this->assertEqualsCanonicalizing(
            [$this->ref($carrier), $this->ref($topic), $this->ref($event)],
            $this->planned($next, HomeIssueSection::Stories),
        );
        $this->assertSame([$this->ref($group)], $this->planned($next, HomeIssueSection::Talk));
        $this->assertSame([$this->ref($event)], $this->planned($next, HomeIssueSection::UpcomingEvents));
    }

    public function test_an_ai_account_is_a_newcomer_like_any_other(): void
    {
        // The member lists an issue sits beside show one, and an issue that quietly did not would be
        // telling the reader something about that account nothing else does.
        $ai = $this->at($this->now()->subHour(), fn (): Member => Member::factory()->aiAccount()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertContains($this->ref($ai), $this->refs($issue, HomeIssueSection::Newcomers));
    }

    public function test_a_switched_off_unit_contributes_nothing_and_runs_no_query(): void
    {
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());
        $this->at($this->now()->subHour(), fn (): Diary => Diary::factory()->create());

        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $issue = $this->publish();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertNotNull($issue);
        $this->assertCount(1, $this->refs($issue, HomeIssueSection::Stories));
        $this->assertSame(
            [],
            $queries->filter(fn (string $sql): bool => str_contains($sql, 'timeline_posts'))->all(),
            'a switched-off unit still cost a query',
        );
    }

    public function test_a_switched_off_group_talk_unit_takes_the_talk_band_with_it(): void
    {
        $group = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());
        $this->burst($group, $this->now()->subHour());
        $this->at($this->now()->subHour(), fn (): Diary => Diary::factory()->create());

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $issue = $this->publish();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertNotNull($issue);
        $this->assertSame([], $this->refs($issue, HomeIssueSection::Talk));
        $this->assertSame([], $queries->filter(fn (string $sql): bool => str_contains($sql, 'group_messages'))->all());
    }
}
