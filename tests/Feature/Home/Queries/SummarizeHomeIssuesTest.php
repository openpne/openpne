<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueSummary;
use App\Features\Home\Data\HydratedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Queries\SummarizeHomeIssues;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SummarizeHomeIssuesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    private Member $viewer;

    private HomeIssue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        $this->viewer = Member::factory()->create();
        $this->issue = $this->publish($this->now());
    }

    /**
     * Every expectation is spelled out rather than read back from the issue page: a gate that
     * admitted everything would keep the two in agreement.
     */
    public function test_a_row_counts_what_survives_and_nothing_else(): void
    {
        $kept = $this->everyDropAndWhatIsLeft();

        $summary = $this->summarize()[$this->issue->getKey()];

        $this->assertSame($kept['stories'], $this->refsOf($summary->stories));
        $this->assertSame($kept['talk'], $this->refsOf(array_column($summary->bursts, 'group')));
        $this->assertSame($kept['newcomers'], $this->refsOf($summary->newcomers));
        $this->assertSame($kept['newGroups'], $this->refsOf($summary->newGroups));

        $this->assertSame(
            ['stories' => 4, 'responses' => 7, 'talk' => 2, 'newcomers' => 1, 'newGroups' => 1],
            $summary->counts(),
        );
    }

    public function test_a_month_shows_what_the_issue_page_shows(): void
    {
        $this->everyDropAndWhatIsLeft();

        $summary = $this->summarize()[$this->issue->getKey()];
        $page = app(ShowHomeIssue::class)($this->viewer, $this->issue);

        $shown = fn (HomeIssueSection $section): array => array_map(
            fn (HydratedItem $item): string => SourceRef::of($item->source)->key(),
            $page->items($section),
        );

        $this->assertSame($shown(HomeIssueSection::Stories), $this->refsOf($summary->stories));
        $this->assertSame($shown(HomeIssueSection::Talk), $this->refsOf(array_column($summary->bursts, 'group')));
        $this->assertSame($shown(HomeIssueSection::Newcomers), $this->refsOf($summary->newcomers));
        $this->assertSame($shown(HomeIssueSection::NewGroups), $this->refsOf($summary->newGroups));
    }

    public function test_a_unit_switched_off_takes_its_rows_out_of_the_count(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create(), rank: 1);
        $post = TimelinePost::factory()->create();
        $this->feature(HomeIssueSection::Stories, $post, rank: 2);

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);

        $summary = $this->summarize()[$this->issue->getKey()];

        $this->assertSame([$this->ref($post)], $this->refsOf($summary->stories));
    }

    public function test_an_rsvp_is_not_a_response(): void
    {
        $event = GroupEvent::factory()->create();
        GroupEventMember::factory()->count(3)->create(['group_event_id' => $event->getKey()]);
        $this->feature(HomeIssueSection::Stories, $event);

        $this->assertSame(0, $this->summarize()[$this->issue->getKey()]->counts()['responses']);
    }

    public function test_the_calendar_is_not_counted(): void
    {
        $this->feature(HomeIssueSection::UpcomingEvents, GroupEvent::factory()->create());

        $summary = $this->summarize()[$this->issue->getKey()];

        $this->assertNull($summary->top());
        $this->assertSame(0, array_sum($summary->counts()));
    }

    public function test_an_issue_with_nothing_left_still_has_a_row(): void
    {
        $diary = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $diary);
        $diary->delete();

        $summaries = $this->summarize();

        $this->assertArrayHasKey($this->issue->getKey(), $summaries);
        $this->assertNull($summaries[$this->issue->getKey()]->top());
    }

    /** A room is counted over its own issue's stretch, whichever issue it is read beside. */
    public function test_each_issue_counts_its_own_stretch_of_a_room(): void
    {
        $group = Group::factory()->create();
        $earlier = $this->publish($this->now()->subDay());

        $this->say($group, $this->now()->subDay()->subHours(2));
        $this->say($group, $this->now()->subHours(3));
        $this->say($group, $this->now()->subHours(2));

        $this->feature(HomeIssueSection::Talk, $group, stats: $this->stretch($this->now()->subDay()), issue: $earlier);
        $this->feature(HomeIssueSection::Talk, $group, stats: $this->stretch($this->now()));

        $summaries = app(SummarizeHomeIssues::class)($this->viewer, collect([$this->issue, $earlier]));

        $this->assertSame(2, $summaries[$this->issue->getKey()]->counts()['talk']);
        $this->assertSame(1, $summaries[$earlier->getKey()]->counts()['talk']);
    }

    public function test_the_top_is_the_first_story_then_talk_then_a_newcomer_then_a_new_group(): void
    {
        $newGroup = Group::factory()->create();
        $this->feature(HomeIssueSection::NewGroups, $newGroup);
        $this->assertTrue($this->top()->is($newGroup));

        $newcomer = Member::factory()->create();
        $this->feature(HomeIssueSection::Newcomers, $newcomer);
        $this->assertTrue($this->top()->is($newcomer));

        $room = Group::factory()->create();
        $this->say($room, $this->now()->subHour());
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));
        $this->assertTrue($this->top()->is($room));

        $second = Diary::factory()->create();
        $first = TimelinePost::factory()->create();
        $this->feature(HomeIssueSection::Stories, $first, rank: 1);
        $this->feature(HomeIssueSection::Stories, $second, rank: 2);
        $this->assertTrue($this->top()->is($first));
    }

    /** Only the top's picture is read: a story further down keeps its relation unloaded. */
    public function test_pictures_are_read_for_the_top_alone(): void
    {
        $first = Diary::factory()->create();
        $second = Diary::factory()->create();
        DiaryImage::factory()->create(['diary_id' => $first->getKey(), 'file_id' => File::factory(), 'number' => 1]);
        DiaryImage::factory()->create(['diary_id' => $second->getKey(), 'file_id' => File::factory(), 'number' => 1]);
        $this->feature(HomeIssueSection::Stories, $first, rank: 1);
        $this->feature(HomeIssueSection::Stories, $second, rank: 2);

        [$top, $below] = $this->summarize()[$this->issue->getKey()]->stories;

        $this->assertTrue($top->relationLoaded('images'));
        $this->assertCount(1, $top->images);
        $this->assertFalse($below->relationLoaded('images'));
    }

    public function test_the_gate_reads_nothing_it_was_not_handed(): void
    {
        $this->everyDropAndWhatIsLeft();

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            $this->fail('lazy-loaded '.$model::class.'::'.$relation);
        });

        try {
            $this->summarize();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * One of each reason a row is dropped, beside what stays.
     *
     * @return array{stories: list<string>, talk: list<string>, newcomers: list<string>, newGroups: list<string>}
     */
    private function everyDropAndWhatIsLeft(): array
    {
        $open = Group::factory()->create();
        $walled = Group::factory()->create();
        $blocker = Member::factory()->create();

        $diary = Diary::factory()->create();
        DiaryComment::factory()->count(2)->create(['diary_id' => $diary->getKey()]);

        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();

        $topic = GroupTopic::factory()->create(['group_id' => $open->getKey()]);
        foreach (range(1, 3) as $number) {
            GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'number' => $number]);
        }

        $event = GroupEvent::factory()->create(['group_id' => $open->getKey()]);
        GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'number' => 1]);
        GroupEventMember::factory()->count(2)->create(['group_event_id' => $event->getKey()]);

        $deleted = Diary::factory()->create();
        $narrowed = Diary::factory()->create();
        $blocked = TimelinePost::factory()->create(['member_id' => $blocker->getKey()]);
        $walledTopic = GroupTopic::factory()->create(['group_id' => $walled->getKey()]);

        foreach ([$diary, $deleted, $post, $narrowed, $topic, $blocked, $reply, $event, $walledTopic] as $rank => $story) {
            $this->feature(HomeIssueSection::Stories, $story, rank: $rank + 1);
        }

        $talking = Group::factory()->create();
        $gone = $this->say($talking, $this->now()->subHours(5));
        $this->say($talking, $this->now()->subHours(4));
        $this->say($talking, $this->now()->subHours(3));

        $emptied = Group::factory()->create();
        $last = $this->say($emptied, $this->now()->subHours(2));

        $this->say($walled, $this->now()->subHours(2));

        foreach ([$emptied, $talking, $walled] as $rank => $room) {
            $this->feature(HomeIssueSection::Talk, $room, rank: $rank + 1, stats: $this->stretch($this->now()));
        }

        $newcomer = Member::factory()->create();
        $this->feature(HomeIssueSection::Newcomers, $blocker, rank: 1);
        $this->feature(HomeIssueSection::Newcomers, $newcomer, rank: 2);

        $newGroup = Group::factory()->create();
        $closed = Group::factory()->create();
        $this->feature(HomeIssueSection::NewGroups, $closed, rank: 1);
        $this->feature(HomeIssueSection::NewGroups, $newGroup, rank: 2);

        $this->feature(HomeIssueSection::UpcomingEvents, $event);

        $deleted->delete();
        $narrowed->update(['visibility' => Visibility::Friends]);
        $walled->update(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $gone->delete();
        $last->delete();
        $closed->delete();
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $this->viewer->getKey()]);

        return [
            'stories' => [$this->ref($diary), $this->ref($post), $this->ref($topic), $this->ref($event)],
            'talk' => [$this->ref($talking)],
            'newcomers' => [$this->ref($newcomer)],
            'newGroups' => [$this->ref($newGroup)],
        ];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    /** An issue over the day that closed at $closing. */
    private function publish(CarbonImmutable $closing): HomeIssue
    {
        return HomeIssue::factory()->create([
            'issue_date' => $closing->subDay()->toDateString(),
            'window_start' => $closing->subDay(),
            'published_at' => $closing,
        ]);
    }

    /** @return array{since: string, until: string} */
    private function stretch(CarbonImmutable $closing): array
    {
        return [
            'since' => $closing->subDay()->toIso8601String(),
            'until' => $closing->toIso8601String(),
        ];
    }

    private function say(Group $group, CarbonImmutable $at): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function feature(
        HomeIssueSection $section,
        Model $source,
        int $rank = 1,
        array $stats = [],
        ?HomeIssue $issue = null,
    ): HomeIssueItem {
        return HomeIssueItem::factory()->forSource($source)->create([
            'home_issue_id' => ($issue ?? $this->issue)->getKey(),
            'section' => $section,
            'rank' => $rank,
            'stats' => $stats,
        ]);
    }

    /** @return array<int, HomeIssueSummary> */
    private function summarize(): array
    {
        return app(SummarizeHomeIssues::class)($this->viewer, collect([$this->issue]));
    }

    private function top(): Model
    {
        $top = $this->summarize()[$this->issue->getKey()]->top();

        $this->assertNotNull($top);

        return $top;
    }

    /**
     * @param  list<Model>  $models
     * @return list<string>
     */
    private function refsOf(array $models): array
    {
        return array_map($this->ref(...), $models);
    }

    private function ref(Model $model): string
    {
        return SourceRef::of($model)->key();
    }
}
