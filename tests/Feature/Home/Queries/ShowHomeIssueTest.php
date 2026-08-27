<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HydratedIssue;
use App\Features\Home\Data\HydratedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopic;
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

/**
 * An issue read back: every ledger row re-resolved through the gate its own feature owns.
 *
 * What is pinned here is mostly what does NOT come out. An issue is a ledger and never a copy, so
 * a source that has gone, been narrowed, been switched off or been walled off from this particular
 * reader leaves nothing at all — no placeholder, no shorter list saying how much was left out.
 */
class ShowHomeIssueTest extends TestCase
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
        $this->issue = HomeIssue::factory()->create([
            'number' => 1,
            'issue_date' => $this->now()->toDateString(),
            'window_start' => $this->now()->subDay(),
            'published_at' => $this->now(),
        ]);
    }

    // --- the source is gone ---

    public function test_a_deleted_source_leaves_nothing_behind(): void
    {
        $kept = Diary::factory()->create();
        $gone = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $gone, rank: 1);
        $this->feature(HomeIssueSection::Stories, $kept, rank: 2);

        $gone->delete();

        $this->assertSame([$this->ref($kept)], $this->refs(HomeIssueSection::Stories));
    }

    public function test_every_kind_of_source_drops_when_it_is_deleted(): void
    {
        $group = Group::factory()->create();
        $sources = [
            HomeIssueSection::Stories->value => [
                TimelinePost::factory()->create(),
                Diary::factory()->create(),
                GroupTopic::factory()->create(['group_id' => $group->getKey()]),
                GroupEvent::factory()->create(['group_id' => $group->getKey()]),
            ],
            HomeIssueSection::Newcomers->value => [Member::factory()->create()],
            HomeIssueSection::NewGroups->value => [Group::factory()->create()],
            HomeIssueSection::UpcomingEvents->value => [GroupEvent::factory()->create(['group_id' => $group->getKey()])],
        ];

        foreach ($sources as $section => $models) {
            foreach ($models as $rank => $model) {
                $this->feature(HomeIssueSection::from($section), $model, rank: $rank + 1);
            }
        }

        // Every band holds something before the sources go.
        foreach (array_keys($sources) as $section) {
            $this->assertNotSame([], $this->refs(HomeIssueSection::from($section)), "{$section} held nothing to begin with");
        }

        foreach ($sources as $models) {
            foreach ($models as $model) {
                $model->delete();
            }
        }

        foreach (array_keys($sources) as $section) {
            $this->assertSame([], $this->refs(HomeIssueSection::from($section)), "{$section} kept a deleted source");
        }
    }

    // --- narrowed after publication ---

    /**
     * The `visibility` arm and the block arm are different questions, and only the owner can tell
     * them apart: a stranger is refused by the eligibility rule and by the clearance rule at once,
     * so a broken eligibility check would still look right from where they stand.
     */
    public function test_a_diary_narrowed_to_friends_drops_for_a_member_and_for_its_own_author(): void
    {
        $diary = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $diary);

        $this->assertSame([$this->ref($diary)], $this->refs(HomeIssueSection::Stories));

        $diary->update(['visibility' => Visibility::Friends]);

        $this->assertSame([], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([], $this->refs(HomeIssueSection::Stories, $diary->member), 'its own author kept it');
    }

    public function test_a_post_narrowed_to_friends_drops_for_a_member_and_for_its_own_author(): void
    {
        $post = TimelinePost::factory()->create();
        $this->feature(HomeIssueSection::Stories, $post);

        $post->update(['visibility' => Visibility::Friends]);

        $this->assertSame([], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([], $this->refs(HomeIssueSection::Stories, $post->member), 'its own author kept it');
    }

    /**
     * A reply is not a story, and nothing about the record itself says so: it carries its root's
     * audience, so every other question asked of it answers yes. An issue that led with one would
     * quote half a conversation.
     */
    public function test_a_reply_is_never_a_story(): void
    {
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create();

        $this->feature(HomeIssueSection::Stories, $reply, rank: 1);
        $this->feature(HomeIssueSection::Stories, $root, rank: 2);

        $this->assertSame([$this->ref($root)], $this->refs(HomeIssueSection::Stories));
    }

    /**
     * "Every member may read it" is the predicate, so a members-only group's board drops for a
     * member of that group too — the reader in front of it is not who the rule is about.
     */
    public function test_a_board_walled_off_after_publication_drops_for_members_and_non_members(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $this->feature(HomeIssueSection::Stories, $topic, rank: 1);
        $this->feature(HomeIssueSection::Stories, $event, rank: 2);
        $this->feature(HomeIssueSection::UpcomingEvents, $event);

        $insider = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $insider->getKey()]);

        $this->assertSame([$this->ref($topic), $this->ref($event)], $this->refs(HomeIssueSection::Stories));

        $group->update(['topic_read_access' => TopicReadAccess::MembersOnly]);

        $this->assertSame([], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([], $this->refs(HomeIssueSection::Stories, $insider), 'a group member kept a walled-off board');
        $this->assertSame([], $this->refs(HomeIssueSection::UpcomingEvents), 'the calendar kept a walled-off event');
    }

    /** A withdrawn author is not a refusal: the record stands and the byline says who it lost. */
    public function test_a_topic_whose_author_has_withdrawn_stays(): void
    {
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create(['member_id' => $author->getKey()]);
        $this->feature(HomeIssueSection::Stories, $topic);

        $author->delete();

        $this->assertSame([$this->ref($topic)], $this->refs(HomeIssueSection::Stories));
        $this->assertNull($this->only(HomeIssueSection::Stories)->source->member);
    }

    /** An issue is a snapshot of the morning it went out, so its calendar keeps the days it named. */
    public function test_the_calendar_keeps_an_event_whose_day_has_passed(): void
    {
        $event = GroupEvent::factory()->create(['open_date' => $this->now()->subWeek()]);
        $this->feature(HomeIssueSection::UpcomingEvents, $event);

        $this->assertSame([$this->ref($event)], $this->refs(HomeIssueSection::UpcomingEvents));
    }

    // --- the reader in front of it ---

    public function test_an_owner_blocking_the_viewer_drops_their_diary_post_and_face(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $post = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);
        $this->feature(HomeIssueSection::Stories, $diary, rank: 1);
        $this->feature(HomeIssueSection::Stories, $post, rank: 2);
        $this->feature(HomeIssueSection::Newcomers, $owner);

        $this->assertSame([$this->ref($diary), $this->ref($post)], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([$this->ref($owner)], $this->refs(HomeIssueSection::Newcomers));

        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $this->viewer->getKey()]);

        $this->assertSame([], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([], $this->refs(HomeIssueSection::Newcomers));
    }

    /** A block is one-way: the member who did the blocking still reads everything they always did. */
    public function test_a_block_the_viewer_made_hides_nothing_from_them(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $this->feature(HomeIssueSection::Stories, $diary);
        $this->feature(HomeIssueSection::Newcomers, $owner);

        DB::table('member_blocks')->insert(['blocker_id' => $this->viewer->getKey(), 'blocked_id' => $owner->getKey()]);

        $this->assertSame([$this->ref($diary)], $this->refs(HomeIssueSection::Stories));
        $this->assertSame([$this->ref($owner)], $this->refs(HomeIssueSection::Newcomers));
    }

    // --- units, read again at render ---

    public function test_a_unit_switched_off_after_publication_hides_its_rows(): void
    {
        $diary = Diary::factory()->create();
        $post = TimelinePost::factory()->create();
        $this->feature(HomeIssueSection::Stories, $diary, rank: 1);
        $this->feature(HomeIssueSection::Stories, $post, rank: 2);

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);

        $this->assertSame([$this->ref($post)], $this->refs(HomeIssueSection::Stories));

        // And switching it back on brings it back, without the ledger having been touched.
        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, true);

        $this->assertSame([$this->ref($diary), $this->ref($post)], $this->refs(HomeIssueSection::Stories));
    }

    /** The map is per (section, source): a group is gated by talk here and by groups over there. */
    public function test_group_talk_off_hides_a_burst_and_leaves_a_new_group_alone(): void
    {
        $group = Group::factory()->create();
        $this->burst($group, [$this->now()->subHours(2)]);
        $this->feature(HomeIssueSection::NewGroups, $group);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);

        $this->assertSame([], $this->refs(HomeIssueSection::Talk));
        $this->assertSame([$this->ref($group)], $this->refs(HomeIssueSection::NewGroups));
    }

    // --- a row nothing could render ---

    /** A row whose alias its section does not hold is dropped, not raised: the front page stays up. */
    public function test_a_row_whose_alias_its_section_does_not_hold_is_dropped(): void
    {
        $diary = Diary::factory()->create();
        $newcomer = Member::factory()->create();
        $this->feature(HomeIssueSection::Newcomers, $diary, rank: 1);
        $this->feature(HomeIssueSection::Newcomers, $newcomer, rank: 2);

        $this->assertSame([$this->ref($newcomer)], $this->refs(HomeIssueSection::Newcomers));
    }

    // --- order and shrinkage ---

    public function test_survivors_keep_the_rank_they_were_published_under(): void
    {
        $first = Diary::factory()->create();
        $second = TimelinePost::factory()->create();
        $third = Diary::factory()->create();
        $fourth = TimelinePost::factory()->create();

        $this->feature(HomeIssueSection::Stories, $first, rank: 1);
        $this->feature(HomeIssueSection::Stories, $second, rank: 2);
        $this->feature(HomeIssueSection::Stories, $third, rank: 3);
        $this->feature(HomeIssueSection::Stories, $fourth, rank: 4);

        $second->delete();

        // Third and fourth move up; they do not reshuffle, and nothing stands where the second was.
        $this->assertSame(
            [$this->ref($first), $this->ref($third), $this->ref($fourth)],
            $this->refs(HomeIssueSection::Stories),
        );
    }

    public function test_a_dropped_row_leaves_no_placeholder(): void
    {
        $kept = Diary::factory()->create();
        $gone = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $kept, rank: 1);
        $this->feature(HomeIssueSection::Stories, $gone, rank: 2);

        $gone->update(['visibility' => Visibility::Private]);

        $this->assertCount(1, $this->hydrate()->items(HomeIssueSection::Stories));
        $this->assertDatabaseCount('home_issue_items', 2);
    }

    /** The frozen stats are provenance; what a page shows is read from the source, live. */
    public function test_a_comment_added_after_publication_is_counted(): void
    {
        $diary = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $diary, stats: ['comments' => 0, 'images' => 0]);

        DiaryComment::factory()->count(2)->create(['diary_id' => $diary->getKey()]);

        $this->assertSame(2, (int) $this->only(HomeIssueSection::Stories)->source->comments_count);
    }

    // --- talk, re-resolved live ---

    public function test_a_burst_in_a_group_walled_off_after_publication_drops(): void
    {
        $group = Group::factory()->create();
        $this->burst($group, [$this->now()->subHours(3)]);

        $group->update(['topic_read_access' => TopicReadAccess::MembersOnly]);

        $this->assertSame([], $this->refs(HomeIssueSection::Talk));
    }

    public function test_a_burst_whose_messages_have_all_gone_drops(): void
    {
        $group = Group::factory()->create();
        [$messages] = $this->burst($group, [$this->now()->subHours(3), $this->now()->subHour()]);

        $this->assertSame([$this->ref($group)], $this->refs(HomeIssueSection::Talk));

        foreach ($messages as $message) {
            $message->delete();
        }

        $this->assertSame([], $this->refs(HomeIssueSection::Talk));
    }

    /** What survives is what is reported: the count is live and the way in is the surviving first. */
    public function test_a_partly_deleted_burst_counts_and_anchors_on_what_is_left(): void
    {
        $group = Group::factory()->create();
        [$messages] = $this->burst($group, [
            $this->now()->subHours(5),
            $this->now()->subHours(4),
            $this->now()->subHours(3),
        ]);

        $messages[0]->delete();

        $burst = $this->only(HomeIssueSection::Talk)->extra;

        $this->assertSame(2, $burst['count']);
        $this->assertSame("/groups/{$group->getKey()}/talk?m={$messages[1]->getKey()}", $burst['href']);
        $this->assertTrue($this->now()->subHours(4)->equalTo($burst['since']), 'since is not the surviving first message');
    }

    /**
     * The faces describe the last day of the stretch, not its start. The first issue ever reaches
     * back a week, and a week-old glimpse of a room talking today is the wrong answer.
     */
    public function test_the_faces_come_from_the_last_day_of_the_stretch(): void
    {
        $group = Group::factory()->create();
        $longAgo = Member::factory()->create();
        $lately = Member::factory()->create();

        $since = $this->now()->subDays(7);
        $first = GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $longAgo->getKey(),
            'created_at' => $since->addDay(),
            'updated_at' => $since->addDay(),
        ]);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $lately->getKey(),
            'created_at' => $this->now()->subHours(2),
            'updated_at' => $this->now()->subHours(2),
        ]);

        $this->feature(HomeIssueSection::Talk, $group, stats: [
            'since' => $since->toIso8601String(),
            'until' => $this->now()->toIso8601String(),
        ]);

        $burst = $this->only(HomeIssueSection::Talk)->extra;

        // Both messages are counted and the anchor is the week-old one — the stretch is the whole
        // window. Only the faces are cut to the last day.
        $this->assertSame(2, $burst['count']);
        $this->assertSame("/groups/{$group->getKey()}/talk?m={$first->getKey()}", $burst['href']);
        $this->assertSame([$lately->getKey()], array_column($burst['participants'], 'id'));
    }

    /** A blank face in a row of faces reads as somebody rather than as nobody. */
    public function test_a_withdrawn_author_is_not_drawn_among_the_faces(): void
    {
        $group = Group::factory()->create();
        $speaker = Member::factory()->create();

        GroupMessage::factory()->withdrawnAuthor()->create([
            'group_id' => $group->getKey(),
            'created_at' => $this->now()->subHours(3),
            'updated_at' => $this->now()->subHours(3),
        ]);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $speaker->getKey(),
            'created_at' => $this->now()->subHours(2),
            'updated_at' => $this->now()->subHours(2),
        ]);

        $this->feature(HomeIssueSection::Talk, $group, stats: $this->window());

        $burst = $this->only(HomeIssueSection::Talk)->extra;

        $this->assertSame(2, $burst['count']);
        $this->assertSame([$speaker->getKey()], array_column($burst['participants'], 'id'));
    }

    public function test_a_refused_picture_is_skipped_in_silence(): void
    {
        $group = Group::factory()->create();
        [$messages] = $this->burst($group, [$this->now()->subHours(3), $this->now()->subHours(2)]);

        $refused = $this->attach($messages[0]);
        $served = $this->attach($messages[1]);

        Gate::before(function (?Member $user, string $ability, array $arguments) use ($refused): ?bool {
            $subject = $arguments[0] ?? null;

            return $ability === 'view' && $subject instanceof File && $subject->is($refused) ? false : null;
        });

        $burst = $this->only(HomeIssueSection::Talk)->extra;

        $this->assertSame([$served->url()], array_column($burst['thumbnails'], 'url'));
    }

    // --- helpers ---

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    /** The issue's own stretch, as a talk row's frozen stats spell it. */
    private function window(): array
    {
        return [
            'since' => $this->now()->subDay()->toIso8601String(),
            'until' => $this->now()->toIso8601String(),
        ];
    }

    private function feature(HomeIssueSection $section, Model $source, int $rank = 1, array $stats = []): HomeIssueItem
    {
        return HomeIssueItem::factory()->forSource($source)->create([
            'home_issue_id' => $this->issue->getKey(),
            'section' => $section,
            'rank' => $rank,
            'stats' => $stats,
        ]);
    }

    /**
     * A featured run of talk: messages at the given instants, plus the ledger row naming the stretch.
     *
     * @param  list<CarbonImmutable>  $said
     * @return array{list<GroupMessage>, HomeIssueItem}
     */
    private function burst(Group $group, array $said): array
    {
        $author = Member::factory()->create();

        $messages = array_map(fn (CarbonImmutable $at): GroupMessage => GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => $at,
            'updated_at' => $at,
        ]), $said);

        return [$messages, $this->feature(HomeIssueSection::Talk, $group, stats: $this->window())];
    }

    /** Attach a picture to $message, owned by it as far as the `files` row is concerned. */
    private function attach(GroupMessage $message): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $message->getKey(),
        ]);

        GroupMessageImage::query()->create([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => 1,
        ]);

        return $file;
    }

    private function hydrate(?Member $viewer = null): HydratedIssue
    {
        return app(ShowHomeIssue::class)($viewer ?? $this->viewer, $this->issue);
    }

    /** @return list<string> the section's surviving sources, in the order they come out */
    private function refs(HomeIssueSection $section, ?Member $viewer = null): array
    {
        return array_map(
            fn (HydratedItem $item): string => SourceRef::of($item->source)->key(),
            $this->hydrate($viewer)->items($section),
        );
    }

    /** The section's one surviving item — an assertion that there is exactly one, and then it. */
    private function only(HomeIssueSection $section, ?Member $viewer = null): HydratedItem
    {
        $items = $this->hydrate($viewer)->items($section);

        $this->assertCount(1, $items, "{$section->value} did not hold exactly one surviving item");

        return $items[0];
    }

    private function ref(Model $model): string
    {
        return SourceRef::of($model)->key();
    }
}
