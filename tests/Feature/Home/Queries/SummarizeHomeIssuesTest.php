<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueSummary;
use App\Features\Home\Data\HydratedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\Data\TalkStretch;
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
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
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
use Illuminate\Support\Facades\Gate;
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

    // --- what survives ---

    /**
     * Every expectation is spelled out rather than read back from the issue page: a gate that
     * admitted everything would keep the two in agreement.
     */
    public function test_a_day_holds_what_survives_and_nothing_else(): void
    {
        $kept = $this->everyDropAndWhatIsLeft();

        $summary = $this->summary();

        $this->assertSame($kept['stories'], $this->refsOf($summary->stories));
        $this->assertSame($kept['talk'], $this->refsOf($this->roomsOf($summary->bursts)));
        $this->assertSame($kept['newcomers'], $this->refsOf($summary->newcomers));
        $this->assertSame($kept['newGroups'], $this->refsOf($summary->newGroups));

        $this->assertSame([2], array_map(fn (TalkStretch $stretch): int => $stretch->count, $summary->bursts));
        $this->assertSame(
            [$kept['stories'][0], $kept['talk'][0], $kept['stories'][1]],
            array_map($this->refOfItem(...), $summary->items()),
        );
        $this->assertSame(2, $summary->more());
    }

    public function test_a_month_shows_what_the_issue_page_shows(): void
    {
        $this->everyDropAndWhatIsLeft();

        $summary = $this->summary();
        $page = app(ShowHomeIssue::class)($this->viewer, $this->issue);

        $shown = fn (HomeIssueSection $section): array => array_map(
            fn (HydratedItem $item): string => SourceRef::of($item->source)->key(),
            $page->items($section),
        );

        $this->assertSame($shown(HomeIssueSection::Stories), $this->refsOf($summary->stories));
        $this->assertSame($shown(HomeIssueSection::Talk), $this->refsOf($this->roomsOf($summary->bursts)));
        // Names are read three rows deep, so a month holds a part of what the page holds.
        $this->assertSame([], array_diff($this->refsOf($summary->newcomers), $shown(HomeIssueSection::Newcomers)));
        $this->assertSame([], array_diff($this->refsOf($summary->newGroups), $shown(HomeIssueSection::NewGroups)));
    }

    public function test_a_unit_switched_off_takes_its_rows_out(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create(), rank: 1);
        $post = TimelinePost::factory()->create();
        $this->feature(HomeIssueSection::Stories, $post, rank: 2);

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);

        $this->assertSame([$this->ref($post)], $this->refsOf($this->summary()->stories));
    }

    /** Three rows are read, so one of them dropped leaves two names and the fourth row is never asked. */
    public function test_names_are_the_first_three_rows_that_pass_the_gate(): void
    {
        $members = Member::factory()->count(4)->create();
        foreach ($members as $rank => $member) {
            $this->feature(HomeIssueSection::Newcomers, $member, rank: $rank + 1);
        }

        DB::table('member_blocks')->insert(['blocker_id' => $members[1]->getKey(), 'blocked_id' => $this->viewer->getKey()]);

        $this->assertSame(
            [$this->ref($members[0]), $this->ref($members[2])],
            $this->refsOf($this->summary()->newcomers),
        );
    }

    public function test_the_calendar_is_not_read(): void
    {
        $this->feature(HomeIssueSection::UpcomingEvents, GroupEvent::factory()->create());

        $summary = $this->summary();

        $this->assertSame([], $summary->items());
        $this->assertSame(0, $summary->more());
    }

    public function test_an_issue_with_nothing_left_still_has_an_entry(): void
    {
        $diary = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $diary);
        $diary->delete();

        $summaries = $this->summarize();

        $this->assertArrayHasKey($this->issue->getKey(), $summaries);
        $this->assertSame([], $summaries[$this->issue->getKey()]->items());
    }

    // --- which three ---

    public function test_a_day_of_stories_shows_the_first_three_and_counts_the_rest(): void
    {
        $stories = Diary::factory()->count(5)->create();
        foreach ($stories as $rank => $story) {
            $this->feature(HomeIssueSection::Stories, $story, rank: $rank + 1);
        }

        $summary = $this->summary();

        $this->assertSame(
            [$this->ref($stories[0]), $this->ref($stories[1]), $this->ref($stories[2])],
            array_map($this->refOfItem(...), $summary->items()),
        );
        $this->assertSame(2, $summary->more());
    }

    public function test_a_day_of_talk_shows_its_rooms(): void
    {
        $rooms = Group::factory()->count(3)->create();
        foreach ($rooms as $rank => $room) {
            $this->say($room, $this->now()->subHours(2));
            $this->feature(HomeIssueSection::Talk, $room, rank: $rank + 1, stats: $this->stretch($this->now()));
        }

        $summary = $this->summary();

        $this->assertSame($this->refsOf($rooms->all()), array_map($this->refOfItem(...), $summary->items()));
        $this->assertSame(0, $summary->more());
    }

    public function test_a_room_takes_the_second_place_and_a_missing_story_gives_up_the_third(): void
    {
        $story = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $story);

        $rooms = Group::factory()->count(3)->create();
        foreach ($rooms as $rank => $room) {
            $this->say($room, $this->now()->subHours(2));
            $this->feature(HomeIssueSection::Talk, $room, rank: $rank + 1, stats: $this->stretch($this->now()));
        }

        $summary = $this->summary();

        $this->assertSame(
            [$this->ref($story), $this->ref($rooms[0]), $this->ref($rooms[1])],
            array_map($this->refOfItem(...), $summary->items()),
        );
        $this->assertSame(1, $summary->more());
    }

    // --- a room, by what was last said in it ---

    public function test_a_stretch_is_open_at_its_start_and_closed_at_its_end(): void
    {
        $room = Group::factory()->create();
        $this->say($room, $this->now()->subDay());
        $this->say($room, $this->now()->subDay()->addSecond());
        $onTheEnd = $this->say($room, $this->now());
        $this->say($room, $this->now()->addSecond());
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        [$stretch] = $this->summary()->bursts;

        $this->assertSame(2, $stretch->count);
        $this->assertTrue($stretch->last->is($onTheEnd));
    }

    public function test_the_last_said_is_the_later_instant_and_then_the_higher_id(): void
    {
        $room = Group::factory()->create();
        $this->say($room, $this->now()->subHours(3));
        $this->say($room, $this->now()->subHours(2));
        $later = $this->say($room, $this->now()->subHours(2));
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        $this->assertTrue($this->summary()->bursts[0]->last->is($later));

        $later->delete();

        [$stretch] = $this->summary()->bursts;
        $this->assertSame(2, $stretch->count);
        $this->assertFalse($stretch->last->is($later));
        $this->assertTrue($stretch->last->created_at->equalTo($this->now()->subHours(2)));
    }

    /** A room is read over its own issue's stretch, whichever issue it is read beside. */
    public function test_each_issue_reads_its_own_stretch_of_a_room(): void
    {
        $room = Group::factory()->create();
        $earlier = $this->publish($this->now()->subDay());

        $yesterday = $this->say($room, $this->now()->subDay()->subHours(2));
        $this->say($room, $this->now()->subHours(3));
        $today = $this->say($room, $this->now()->subHours(2));

        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()->subDay()), issue: $earlier);
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        $summaries = app(SummarizeHomeIssues::class)($this->viewer, collect([$this->issue, $earlier]));

        $this->assertSame(2, $summaries[$this->issue->getKey()]->bursts[0]->count);
        $this->assertTrue($summaries[$this->issue->getKey()]->bursts[0]->last->is($today));
        $this->assertSame(1, $summaries[$earlier->getKey()]->bursts[0]->count);
        $this->assertTrue($summaries[$earlier->getKey()]->bursts[0]->last->is($yesterday));
    }

    public function test_a_room_whose_messages_have_all_gone_is_not_a_room_of_the_day(): void
    {
        $room = Group::factory()->create();
        $said = $this->say($room, $this->now()->subHours(2));
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        $said->delete();

        $this->assertSame([], $this->summary()->bursts);
    }

    public function test_a_withdrawn_speaker_keeps_the_line(): void
    {
        $room = Group::factory()->create();
        GroupMessage::factory()->withdrawnAuthor()->create([
            'group_id' => $room->getKey(),
            'created_at' => $this->now()->subHours(2),
            'updated_at' => $this->now()->subHours(2),
        ]);
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        [$stretch] = $this->summary()->bursts;

        $this->assertSame(1, $stretch->count);
        $this->assertNull($stretch->last->author);
    }

    // --- a room's picture is what its gate let through ---

    public function test_a_picture_the_gate_lets_through_is_the_rooms_picture(): void
    {
        $room = Group::factory()->create();
        $file = $this->attach($this->say($room, $this->now()->subHours(2)));
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        $this->assertSame($file->url(), $this->summary()->bursts[0]->picture['url'] ?? null);
    }

    public function test_a_refused_picture_is_no_picture(): void
    {
        $room = Group::factory()->create();
        $refused = $this->attach($this->say($room, $this->now()->subHours(2)));
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        Gate::before(function (?Member $user, string $ability, array $arguments) use ($refused): ?bool {
            $subject = $arguments[0] ?? null;

            return $ability === 'view' && $subject instanceof File && $subject->is($refused) ? false : null;
        });

        [$stretch] = $this->summary()->bursts;

        $this->assertNull($stretch->picture);
        $this->assertSame(1, $stretch->count);
    }

    public function test_a_file_owned_by_another_message_is_no_picture(): void
    {
        $room = Group::factory()->create();
        $other = $this->say($room, $this->now()->subHours(5));
        $this->attach($this->say($room, $this->now()->subHours(2)), owner: $other);
        $this->feature(HomeIssueSection::Talk, $room, stats: $this->stretch($this->now()));

        $this->assertNull($this->summary()->bursts[0]->picture);
    }

    /** A fourth room is never shown, so neither its picture nor the gate of it is read. */
    public function test_pictures_are_read_for_what_is_shown_and_no_more(): void
    {
        $stories = Diary::factory()->count(4)->create();
        foreach ($stories as $rank => $story) {
            DiaryImage::factory()->create(['diary_id' => $story->getKey(), 'file_id' => File::factory(), 'number' => 1]);
            $this->feature(HomeIssueSection::Stories, $story, rank: $rank + 1);
        }

        $read = $this->summary()->stories;

        $this->assertSame(
            [true, true, true, false],
            array_map(fn (Model $story): bool => $story->relationLoaded('images'), $read),
        );
    }

    public function test_nothing_is_loaded_a_row_at_a_time(): void
    {
        $this->everyDropAndWhatIsLeft();

        $pictured = Group::factory()->create(['file_id' => File::factory()]);
        $this->attach($this->say($pictured, $this->now()->subHours(2)));
        $this->feature(HomeIssueSection::Talk, $pictured, rank: 9, stats: $this->stretch($this->now()));

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            // The file policy reads the owner of a picture it is asked about, one picture at a time.
            if ($model instanceof GroupMessage && $relation === 'group') {
                return;
            }

            $this->fail('lazy-loaded '.$model::class.'::'.$relation);
        });

        try {
            $this->summarize();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->addToAssertionCount(1);
    }

    // --- helpers ---

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

    /** An issue over the day that closed at $closing, numbered by the factory like every other here. */
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

    /** Attach a picture to $message, owned by $owner as far as the `files` row is concerned. */
    private function attach(GroupMessage $message, ?GroupMessage $owner = null): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => ($owner ?? $message)->getKey(),
        ]);

        GroupMessageImage::query()->create([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => 1,
        ]);

        return $file;
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

    private function summary(): HomeIssueSummary
    {
        return $this->summarize()[$this->issue->getKey()];
    }

    /**
     * @param  list<TalkStretch>  $bursts
     * @return list<Group>
     */
    private function roomsOf(array $bursts): array
    {
        return array_map(fn (TalkStretch $stretch): Group => $stretch->group, $bursts);
    }

    private function refOfItem(Model|TalkStretch $item): string
    {
        return $this->ref($item instanceof TalkStretch ? $item->group : $item);
    }

    /**
     * @param  array<int, Model>  $models
     * @return list<string>
     */
    private function refsOf(array $models): array
    {
        return array_values(array_map($this->ref(...), $models));
    }

    private function ref(Model $model): string
    {
        return SourceRef::of($model)->key();
    }
}
