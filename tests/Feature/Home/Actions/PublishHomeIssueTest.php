<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssuePlan;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * The publisher deciding what an issue holds, and committing that decision once.
 *
 * `$now` is always passed rather than read, because it is the only clock in the design: the window
 * closes on it, the issue is dated by it, and the calendar looks forward from it.
 */
class PublishHomeIssueTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    /** Whether {@see raceInARivalIssue} actually fired — an assertion, not bookkeeping. */
    private bool $raced = false;

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

    public function test_the_calendar_runs_from_the_publish_days_own_midnight_to_seven_days_out(): void
    {
        // `open_date` is a date, so the day's own events sit at midnight — six hours behind the
        // publishing instant. A calendar bounded by that instant would drop the one event a reader
        // can still act on today.
        [$yesterday, $today, $tomorrow, $lastDay, $justPast] = $this->at(
            $this->now()->subDays(30),
            fn (): array => array_map(
                fn (int $days): GroupEvent => GroupEvent::factory()->create([
                    'open_date' => $this->now()->addDays($days)->startOfDay(),
                ]),
                [-1, 0, 1, 7, 8],
            ),
        );

        // The calendar never triggers an issue, so something else has to carry this one.
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, HomeIssueSection::UpcomingEvents);
        $this->assertSame([$this->ref($today), $this->ref($tomorrow), $this->ref($lastDay)], $refs);
        $this->assertNotContains($this->ref($yesterday), $refs);
        $this->assertNotContains($this->ref($justPast), $refs);
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
        // Two kinds, because the tiebreak only decides anything in the merge — inside one kind the
        // query has already ordered by the same keys. The newer story is made second but starts its
        // own table's ids, so the id tiebreak cannot stand in for the instant under test.
        $older = $this->at($this->now()->subHours(3), fn (): TimelinePost => $this->postWithReplies(2));
        $newer = $this->at($this->now()->subHours(2), function (): Diary {
            $diary = Diary::factory()->create();
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

    public function test_a_burst_needs_three_messages(): void
    {
        $quiet = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());
        $talking = $this->at($this->now()->subDays(30), fn (): Group => Group::factory()->create());

        $this->burst($quiet, $this->now()->subHour(), 2);
        $this->burst($talking, $this->now()->subHour(), 3);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($talking)], $this->refs($issue, HomeIssueSection::Talk));
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

        // Flipping the one column admits all three, which is what makes the absences above the read
        // gate rather than anything else about the group. Read twice at the same instant, so the
        // window is held still and only the gate moves.
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

    public function test_a_member_with_no_created_at_is_in_no_window(): void
    {
        $undated = $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());
        $dated = $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        Member::whereKey($undated->id)->update(['created_at' => null]);

        $issue = $this->publish();

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($dated)], $this->refs($issue, HomeIssueSection::Newcomers));
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

    public function test_a_pin_leads_and_the_algorithm_shifts_down(): void
    {
        $posts = [];
        $this->at($this->now()->subHours(3), function () use (&$posts): void {
            foreach (range(HomeIssueSection::Stories->cap(), 1) as $replies) {
                $posts[$replies] = $this->postWithReplies($replies);
            }
        });

        // Older than the window: a pin overrides the window as well as the ranking.
        $pinned = $this->at($this->now()->subDays(30), fn (): Diary => Diary::factory()->create());

        $issue = $this->publish(pin: SourceRef::of($pinned));

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, HomeIssueSection::Stories);
        $this->assertCount(HomeIssueSection::Stories->cap(), $refs);
        $this->assertSame($this->ref($pinned), $refs[0]);
        $this->assertSame($this->ref($posts[HomeIssueSection::Stories->cap()]), $refs[1]);
        $this->assertNotContains($this->ref($posts[1]), $refs, 'the cap did not shift the last story out');
    }

    public function test_a_pin_the_algorithm_also_chose_is_not_featured_twice(): void
    {
        $post = $this->at($this->now()->subHour(), fn (): TimelinePost => $this->postWithReplies(3));
        $other = $this->at($this->now()->subHour(), fn (): TimelinePost => $this->postWithReplies(9));

        $issue = $this->publish(pin: SourceRef::of($post));

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($post), $this->ref($other)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_pin_no_member_may_read_is_ignored(): void
    {
        $private = $this->at($this->now()->subDays(30), fn (): TimelinePost => TimelinePost::factory()->private()->create());
        $carrier = $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $plan = $this->action()->plan($this->now(), SourceRef::of($private));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($private), $plan->ignoredPin?->key());
        $this->assertSame([$this->ref($carrier)], $this->planned($plan, HomeIssueSection::Stories));
    }

    public function test_a_pin_that_is_not_a_story_is_ignored(): void
    {
        $member = $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        $plan = $this->action()->plan($this->now(), new SourceRef('member', (int) $member->id));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($member), $plan->ignoredPin?->key());
        $this->assertSame([], $this->planned($plan, HomeIssueSection::Stories));
        $this->assertContains($this->ref($member), $this->planned($plan, HomeIssueSection::Newcomers));
    }

    public function test_a_pin_whose_unit_is_off_is_ignored(): void
    {
        $diary = $this->at($this->now()->subHour(), fn (): Diary => Diary::factory()->create());
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);

        $plan = $this->action()->plan($this->now(), SourceRef::of($diary));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($diary), $plan->ignoredPin?->key());
    }

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
        $existing = HomeIssue::factory()->create([
            'number' => 41,
            'issue_date' => $this->now()->toDateString(),
            'window_start' => $this->now()->subDay(),
            'published_at' => $this->now()->subHours(2),
        ]);
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

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    private function action(): PublishHomeIssue
    {
        return app(PublishHomeIssue::class);
    }

    private function publish(?CarbonImmutable $now = null, ?SourceRef $pin = null): ?HomeIssue
    {
        return ($this->action())($now ?? $this->now(), $pin);
    }

    /** Run $make as if it were $when, so every row it writes is stamped there. */
    private function at(CarbonImmutable $when, callable $make): mixed
    {
        Carbon::setTestNow($when);

        try {
            return $make();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function previousIssue(CarbonImmutable $publishedAt, int $number = 1): HomeIssue
    {
        return HomeIssue::factory()->create([
            'number' => $number,
            'issue_date' => $publishedAt->toDateString(),
            'window_start' => $publishedAt->subDay(),
            'published_at' => $publishedAt,
        ]);
    }

    private function postWithReplies(int $replies): TimelinePost
    {
        $post = TimelinePost::factory()->create();
        TimelinePost::factory()->count($replies)->replyTo($post)->create();

        return $post;
    }

    /** $count messages in $group, all written at $when. */
    private function burst(Group $group, CarbonImmutable $when, int $count = 3): void
    {
        $this->at($when, fn () => GroupMessage::factory()->count($count)->for($group)->create());
    }

    /**
     * Publish a rival issue for the day while the run is still reading — after its "is it published?"
     * check and before its transaction, which is the only window the DB unique has to cover. Driven
     * from inside the run because the suite has one connection: a row written after the transaction
     * opened would roll back with it and prove nothing.
     *
     * @param  (callable(): void)|null  $then  runs once the rival is in, for a test that also wants
     *                                         to break the write that follows
     */
    private function raceInARivalIssue(?callable $then = null): void
    {
        $this->raced = false;

        Member::retrieved(function () use ($then): void {
            if ($this->raced) {
                return;
            }
            $this->raced = true;

            DB::table('home_issues')->insert([
                'number' => 999,
                // Written the way the model writes it, so the unique sees one value and not two
                // spellings of a day (see PublishHomeIssue::publishedOn).
                'issue_date' => $this->now()->startOfDay(),
                'window_start' => $this->now()->subDay(),
                'published_at' => $this->now(),
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            if ($then !== null) {
                $then();
            }
        });
    }

    /** Refuse the run's insert into `home_issues` the way SQLite refuses a writer it cannot serialize. */
    private function failTheWrite(): void
    {
        $thrown = false;

        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$thrown): void {
            if ($thrown || ! str_contains($query, 'insert into') || ! str_contains($query, 'home_issues')) {
                return;
            }
            $thrown = true;

            throw new QueryException(
                $connection->getName(),
                $query,
                $bindings,
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
            );
        });
    }

    /**
     * Stop the connection recognising a concurrency error, so a busy database can be simulated here
     * at all.
     *
     * RefreshDatabase runs each test inside a transaction, which makes the publisher's a nested one —
     * and the framework answers a concurrency error in a nested transaction with a DeadlockException
     * instead of retrying it (ManagesTransactions::handleTransactionException). Switched off, the
     * connection takes the path an unnested one reaches once its last attempt has failed: roll back,
     * and rethrow the QueryException the driver raised. What the retry itself does is the framework's
     * to test.
     */
    private function concurrencyDetectionOff(): void
    {
        $this->app->instance(ConcurrencyErrorDetector::class, new class implements ConcurrencyErrorDetector
        {
            public function causedByConcurrencyError(Throwable $e): bool
            {
                return false;
            }
        });
    }

    private function react(GroupMessage $message, int $count): void
    {
        // Reactions have no factory, and the unique key is (content, member, emoji).
        foreach (Member::factory()->count($count)->create() as $member) {
            DB::table('reactions')->insert([
                'reactable_type' => $message->getMorphClass(),
                'reactable_id' => $message->getKey(),
                'member_id' => $member->id,
                'emoji' => '👍',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
    }

    private function ref(object $model): string
    {
        return SourceRef::of($model)->key();
    }

    /** @return list<string> the section's planned sources, in rank order */
    private function planned(HomeIssuePlan $plan, HomeIssueSection $section): array
    {
        return array_map(fn (PlannedItem $item): string => $item->ref()->key(), $plan->items($section));
    }

    /** @return list<string> the section's sources, in rank order */
    private function refs(HomeIssue $issue, HomeIssueSection $section): array
    {
        return $issue->items()
            ->where('section', $section)
            ->orderBy('rank')
            ->get()
            ->map(fn (HomeIssueItem $item): string => $item->source_type.':'.$item->source_id)
            ->all();
    }

    private function item(HomeIssue $issue, HomeIssueSection $section, string $ref): HomeIssueItem
    {
        [$type, $id] = explode(':', $ref);

        $item = $issue->items()
            ->where('section', $section)
            ->where('source_type', $type)
            ->where('source_id', (int) $id)
            ->first();

        $this->assertNotNull($item, "{$section->value} does not hold {$ref}");

        return $item;
    }
}
