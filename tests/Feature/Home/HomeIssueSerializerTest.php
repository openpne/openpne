<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\ListHomeIssues;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Diary;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostMention;
use App\Support\BodyFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The payload an issue page reads.
 *
 * Its subject is the shape: how much survived decides which keys are there, and an empty section is
 * a missing key rather than an empty list — so nothing on the page has to decide what `[]` means.
 */
class HomeIssueSerializerTest extends TestCase
{
    use RefreshDatabase;

    /** A 06:00 publication, which dates its issue to the day that just ended (HomeIssueDay). */
    private const NOW = '2026-08-28 06:00:00';

    private Member $viewer;

    private HomeIssue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        $this->viewer = Member::factory()->create();
        $this->issue = HomeIssue::factory()->create([
            'number' => 7,
            'issue_date' => $this->now()->subDay()->toDateString(),
            'window_start' => $this->now()->subDay(),
            'published_at' => $this->now(),
        ]);
    }

    // --- every story, whole ---

    public function test_a_story_is_carried_whole(): void
    {
        $diary = Diary::factory()->create([
            'format' => BodyFormat::Markdown,
            'body' => "**bold**\n\nand more",
        ]);
        $this->feature(HomeIssueSection::Stories, $diary);

        $issue = $this->page()['issue'];

        $this->assertCount(1, $issue['stories']);
        $this->assertSame('diary', $issue['stories'][0]['kind']);

        // The page prints bodies, so a story carries what a show page carries — the rendered body a
        // preview would have no use for.
        $this->assertStringContainsString('<strong>bold</strong>', (string) $issue['stories'][0]['item']['bodyHtml']);
        $this->assertSame($diary->body, $issue['stories'][0]['item']['body']);
    }

    public function test_a_leading_post_carries_the_ranges_its_body_is_drawn_with(): void
    {
        $mentioned = Member::factory()->create();
        $post = TimelinePost::factory()->create(['body' => "hi @{$mentioned->name}\nrest of it"]);
        TimelinePostMention::query()->create([
            'timeline_post_id' => $post->getKey(),
            'member_id' => $mentioned->getKey(),
            'offset' => 3,
            'length' => mb_strlen($mentioned->name) + 1,
        ]);
        $this->feature(HomeIssueSection::Stories, $post);

        $item = $this->page()['issue']['stories'][0]['item'];

        $this->assertSame([$mentioned->getKey()], array_column($item['mentions'], 'memberId'));
    }

    /** Rank order, and every rank in the same shape: the page decides how much of each it prints. */
    public function test_every_rank_arrives_in_the_same_shape_in_rank_order(): void
    {
        $ranked = [];

        foreach (range(1, 4) as $rank) {
            $ranked[] = $diary = Diary::factory()->create(['title' => "Story {$rank}"]);
            $this->feature(HomeIssueSection::Stories, $diary, rank: $rank);
        }

        $stories = $this->page()['issue']['stories'];

        $this->assertSame(
            array_map(fn (Diary $diary): int => $diary->getKey(), $ranked),
            array_column(array_column($stories, 'item'), 'id'),
        );

        foreach ($stories as $story) {
            $this->assertArrayHasKey('body', $story['item'], 'a story below the lead lost its body');
            $this->assertArrayHasKey('images', $story['item']);
            $this->assertArrayHasKey('excerpt', $story['item']);
        }
    }

    /**
     * The band is what survived, not what was published: an issue of four that lost three is an
     * issue of one.
     */
    public function test_the_band_follows_the_survivors_not_the_ledger(): void
    {
        $kept = Diary::factory()->create();
        $this->feature(HomeIssueSection::Stories, $kept, rank: 1);

        $gone = [];
        foreach (range(2, 4) as $rank) {
            $gone[] = $diary = Diary::factory()->create();
            $this->feature(HomeIssueSection::Stories, $diary, rank: $rank);
        }

        foreach ($gone as $diary) {
            $diary->delete();
        }

        $issue = $this->page()['issue'];

        $this->assertCount(1, $issue['stories']);
        $this->assertSame($kept->getKey(), $issue['stories'][0]['item']['id']);
    }

    // --- which days it is about ---

    /**
     * A day of happenings runs 06:00 to 06:00, so the page names days and the stretch they were
     * drawn from separately: the URL day is the last of them.
     */
    public function test_an_issue_names_the_days_it_covers_and_the_stretch_behind_them(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $issue = $this->page()['issue'];

        $this->assertSame('2026-08-27', $issue['date']);
        $this->assertSame(['from' => '2026-08-27', 'to' => '2026-08-27'], $issue['days']);
        $this->assertSame(
            [$this->now()->subDay()->toIso8601String(), $this->now()->toIso8601String()],
            [$issue['window']['from'], $issue['window']['to']],
        );
    }

    /**
     * The default fixture is a row the publisher could have written: dated by the last day of its
     * own window. `number` is pinned only to stay off the one setUp already took.
     */
    public function test_a_factory_issue_is_dated_by_the_last_day_it_covers(): void
    {
        $this->issue = HomeIssue::factory()->create(['number' => 99]);
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $issue = $this->page()['issue'];

        $this->assertSame($issue['date'], $issue['days']['to']);
    }

    /** A longer stretch is a range of days, and still dated by the last of them. */
    public function test_a_stretch_of_several_days_reports_all_of_them(): void
    {
        $this->issue->update(['window_start' => $this->now()->subDays(7)]);
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $issue = $this->page()['issue'];

        $this->assertSame(['from' => '2026-08-21', 'to' => '2026-08-27'], $issue['days']);
        $this->assertSame('2026-08-27', $issue['date']);
    }

    // --- what a story carries that its record does not ---

    /** The page headlines a post with its first line, so the excerpt must not print it again. */
    public function test_a_posts_excerpt_is_what_is_left_after_its_first_line(): void
    {
        $post = TimelinePost::factory()->create(['body' => "the headline\nthe rest\nand more"]);
        $single = TimelinePost::factory()->create(['body' => 'one line only']);
        $this->feature(HomeIssueSection::Stories, $post, rank: 1);
        $this->feature(HomeIssueSection::Stories, $single, rank: 2);

        $issue = $this->page()['issue'];

        $this->assertSame("the rest\nand more", $issue['stories'][0]['item']['excerpt']);
        $this->assertSame('', $issue['stories'][1]['item']['excerpt']);
    }

    public function test_a_board_story_carries_its_group_its_lead_and_its_comment_count(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'body' => 'what the topic says']);
        GroupTopicComment::factory()->count(3)->create(['group_topic_id' => $topic->getKey()]);
        $this->feature(HomeIssueSection::Stories, $topic);

        $item = $this->page()['issue']['stories'][0]['item'];

        $this->assertSame($group->getKey(), $item['group']['id']);
        $this->assertSame($group->name, $item['group']['name']);
        $this->assertSame('what the topic says', $item['excerpt']);
        // The detail shape has no comment count of its own; a story is read with one.
        $this->assertSame(3, $item['commentCount']);
    }

    public function test_an_event_story_carries_its_counts_too(): void
    {
        $event = GroupEvent::factory()->create(['body' => 'what the event says']);
        $this->feature(HomeIssueSection::Stories, $event);

        $item = $this->page()['issue']['stories'][0]['item'];

        $this->assertSame(0, $item['commentCount']);
        $this->assertSame(0, $item['participantCount']);
        $this->assertSame($event->open_date->format('Y-m-d'), $item['openDate']);
    }

    // --- where everything points ---

    public function test_each_band_names_where_it_is_read(): void
    {
        $newcomer = Member::factory()->create();
        $newGroup = Group::factory()->create();
        $talking = Group::factory()->create();
        $said = GroupMessage::factory()->create([
            'group_id' => $talking->getKey(),
            'created_at' => $this->now()->subHours(2),
            'updated_at' => $this->now()->subHours(2),
        ]);

        $this->feature(HomeIssueSection::Newcomers, $newcomer);
        $this->feature(HomeIssueSection::NewGroups, $newGroup);
        $this->feature(HomeIssueSection::Talk, $talking, stats: [
            'since' => $this->now()->subDay()->toIso8601String(),
            'until' => $this->now()->toIso8601String(),
        ]);

        $issue = $this->page()['issue'];

        $this->assertSame('/home/2026/08/27', $issue['href']);
        $this->assertSame("/member/{$newcomer->getKey()}", $issue['newcomers'][0]['href']);
        $this->assertSame("/groups/{$newGroup->getKey()}", $issue['newGroups'][0]['href']);
        // The way into a burst is the message it starts on, not the foot of the room.
        $this->assertSame("/groups/{$talking->getKey()}/talk?m={$said->getKey()}", $issue['talkBursts'][0]['href']);
        $this->assertSame($talking->getKey(), $issue['talkBursts'][0]['group']['id']);
        $this->assertSame(1, $issue['talkBursts'][0]['count']);
    }

    /**
     * A burst is a conversation to read: the last turns of the stretch, oldest first, each in the
     * shape the room's own stream ships them in — with the pictures the reader may have.
     */
    public function test_a_burst_carries_the_end_of_the_conversation(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();

        $said = array_map(function (int $minute) use ($group, $author): GroupMessage {
            $at = $this->now()->subHours(3)->addMinutes($minute);

            return GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $author->getKey(),
                'body' => "turn {$minute}",
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }, range(1, TalkSampleDigest::EXCERPT + 2));

        $picture = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $said[2]->getKey(),
        ]);
        GroupMessageImage::query()->create([
            'group_message_id' => $said[2]->getKey(),
            'file_id' => $picture->getKey(),
            'number' => 1,
        ]);

        $this->feature(HomeIssueSection::Talk, $group, stats: [
            'since' => $this->now()->subDay()->toIso8601String(),
            'until' => $this->now()->toIso8601String(),
        ]);

        $burst = $this->page()['issue']['talkBursts'][0];

        // How much was said is the whole stretch; what is printed is the end of it.
        $this->assertSame(TalkSampleDigest::EXCERPT + 2, $burst['count']);
        $this->assertSame(
            array_map(fn (GroupMessage $message): int => $message->getKey(), array_slice($said, 2)),
            array_column($burst['messages'], 'id'),
        );

        $this->assertSame(
            ['id', 'author', 'body', 'mentions', 'createdAt', 'images'],
            array_keys($burst['messages'][0]),
        );
        $this->assertSame($author->getKey(), $burst['messages'][0]['author']['id']);
        $this->assertSame('turn 3', $burst['messages'][0]['body']);
        $this->assertSame([$picture->url()], array_column($burst['messages'][0]['images'], 'url'));

        // The faces and the pictures are the excerpt's; nothing is reported twice beside it.
        $this->assertSame(['group', 'count', 'messages', 'href'], array_keys($burst));
    }

    /** The faces grid's shape is a contract between the pages that draw it, not a detail of one. */
    public function test_a_newcomer_arrives_in_the_shape_every_faces_grid_reads(): void
    {
        $this->feature(HomeIssueSection::Newcomers, Member::factory()->create());

        $this->assertSame(
            ['id', 'name', 'imageUrl', 'avatarColor', 'isAi', 'href'],
            array_keys($this->page()['issue']['newcomers'][0]),
        );
    }

    public function test_a_calendar_row_carries_the_day_it_falls_on(): void
    {
        $event = GroupEvent::factory()->create(['open_date' => $this->now()->addDays(3)]);
        $this->feature(HomeIssueSection::UpcomingEvents, $event);

        $row = $this->page()['issue']['upcomingEvents'][0];

        $this->assertSame($this->now()->addDays(3)->format('Y-m-d'), $row['openDate']);
        $this->assertSame('event', $row['kind']);
    }

    // --- the colophon and the pager ---

    /**
     * "Current" is whether the page is showing what there is, not whether it is dated today: the
     * issue a reader is handed all day covers the day before, and comparing it to the calendar
     * would make every fresh front page announce itself as stale.
     */
    public function test_an_issue_says_whether_it_is_the_freshest_there_could_be(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $this->assertTrue($this->page()['issue']['isCurrent']);

        // A day was missed: the 27th's issue is no longer the last one that could have come out.
        $this->issue->update(['issue_date' => $this->now()->subDays(2)->toDateString()]);

        $this->assertFalse($this->page()['issue']['isCurrent']);
    }

    public function test_the_pager_names_the_issues_either_side(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $previous = HomeIssue::factory()->create([
            'number' => 6,
            'issue_date' => $this->now()->subDays(2)->toDateString(),
            'window_start' => $this->now()->subDays(2),
            'published_at' => $this->now()->subDay(),
        ]);

        $page = $this->page(previous: $previous);

        $this->assertSame(
            ['date' => '2026-08-26', 'number' => 6, 'href' => '/home/2026/08/26'],
            $page['prev'],
        );
        $this->assertNull($page['next']);
    }

    public function test_a_day_with_no_issue_is_a_null_issue_with_the_pager_intact(): void
    {
        $page = HomeIssueSerializer::page(null, null, $this->viewer, null, null, $this->now());

        $this->assertNull($page['issue']);
        $this->assertNull($page['prev']);
        $this->assertNull($page['next']);
    }

    // --- what an empty section looks like ---

    public function test_an_empty_section_is_a_missing_key_and_never_an_empty_list(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $issue = $this->page()['issue'];

        foreach (['talkBursts', 'newcomers', 'newGroups', 'upcomingEvents'] as $key) {
            $this->assertFalse(array_key_exists($key, $issue), "`{$key}` was shipped as an empty section");
        }
    }

    // --- the archive index ---

    public function test_the_archive_lists_issues_newest_first_with_the_pager_state(): void
    {
        HomeIssue::factory()->create([
            'number' => 6,
            'issue_date' => $this->now()->subDays(2)->toDateString(),
            'window_start' => $this->now()->subDays(2),
            'published_at' => $this->now()->subDay(),
        ]);

        $archive = HomeIssueSerializer::archive(app(ListHomeIssues::class)());

        $this->assertSame([7, 6], array_column($archive['issues']['data'], 'number'));
        $this->assertSame(['2026-08-27', '2026-08-26'], array_column($archive['issues']['data'], 'date'));
        $this->assertSame(
            ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 30, 'total' => 2],
            $archive['issues']['meta'],
        );
    }

    // --- the shell's props ---

    /**
     * Page props win the Inertia merge, so a payload key named after a shared prop silently blanks
     * it for every component on the page. The guard that scrapes literal render arrays cannot see a
     * serializer-built payload, so this one asks the middleware itself.
     */
    public function test_no_payload_key_shadows_a_shared_prop(): void
    {
        $this->feature(HomeIssueSection::Stories, Diary::factory()->create());

        $request = Request::create('/home/2026/08/27');
        $request->setLaravelSession(app('session.store'));
        $shared = array_keys((new HandleInertiaRequests)->share($request));

        $this->assertNotEmpty($shared, 'share() answered no props — the guard has gone stale.');

        foreach ([$this->page(), HomeIssueSerializer::archive(app(ListHomeIssues::class)())] as $payload) {
            $this->assertSame([], array_intersect(array_keys($payload), $shared));
        }
    }

    // --- helpers ---

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
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

    private function page(?HomeIssue $previous = null, ?HomeIssue $next = null): array
    {
        $issue = $this->issue->fresh();

        return HomeIssueSerializer::page(
            $issue,
            app(ShowHomeIssue::class)($this->viewer, $issue),
            $this->viewer,
            $previous,
            $next,
            $this->now(),
        );
    }
}
