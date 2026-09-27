<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueMonth;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\AdjacentHomeIssueMonths;
use App\Features\Home\Queries\ListHomeIssueMonths;
use App\Features\Home\Queries\ListHomeIssuesInMonth;
use App\Features\Home\Queries\SummarizeHomeIssues;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventImage;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopic;
use App\Models\GroupTopicImage;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A month costs what a day of it costs, but for a read per issue that holds talk and the gate of
 * each talk picture shown (docs/internals/home-issues.md, "The month page").
 */
class HomeIssueMonthQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** What the file policy reads to answer for one picture: its owner, the owner's room and the two settings its unit hangs on. */
    private const PER_PICTURE = 4;

    /** The pictures of every message shown and their files, read once for the month. */
    private const PICTURES = 2;

    protected function setUp(): void
    {
        parent::setUp();

        // The store a site runs on by default, where a settings read is a query and is counted.
        config(['cache.default' => 'database']);

        Carbon::setTestNow('2026-09-01 06:00:00');
    }

    public function test_a_month_costs_the_same_however_many_issues_it_holds(): void
    {
        $viewer = Member::factory()->create();

        $this->fill(new HomeIssueMonth(2026, 6), days: 15, talking: 10, pictured: 5);
        $this->fill(new HomeIssueMonth(2026, 7), days: 31, talking: 10, pictured: 5);

        $this->warm($viewer);

        [$half, $halfDays] = $this->measure($viewer, new HomeIssueMonth(2026, 6));
        [$full, $fullDays] = $this->measure($viewer, new HomeIssueMonth(2026, 7));

        $this->assertFilled($halfDays, days: 15, talking: 10, pictured: 5);
        $this->assertFilled($fullDays, days: 31, talking: 10, pictured: 5);

        $this->assertSame($half, $full, 'a read grew with the days of the month');
    }

    public function test_a_room_costs_one_read_an_issue_and_a_picture_its_gate(): void
    {
        $viewer = Member::factory()->create();

        $this->fill(new HomeIssueMonth(2026, 3), days: 31, talking: 0, pictured: 0);
        $this->fill(new HomeIssueMonth(2026, 5), days: 31, talking: 1, pictured: 0);
        $this->fill(new HomeIssueMonth(2026, 7), days: 31, talking: 31, pictured: 0);
        $this->fill(new HomeIssueMonth(2026, 8), days: 31, talking: 31, pictured: 31);

        $this->warm($viewer);

        [$silent] = $this->measure($viewer, new HomeIssueMonth(2026, 3));
        [$one] = $this->measure($viewer, new HomeIssueMonth(2026, 5));
        [$talking] = $this->measure($viewer, new HomeIssueMonth(2026, 7));
        [$pictured, $days] = $this->measure($viewer, new HomeIssueMonth(2026, 8));

        $this->assertFilled($days, days: 31, talking: 31, pictured: 31);

        // What the first room brings is read once for the month; every issue after it adds its stretch.
        $this->assertSame(30, $talking - $one, 'an issue that holds talk cost more than its stretch');
        $this->assertGreaterThan($silent, $one);

        $this->assertGreaterThan(0, $pictured - $talking);
        $this->assertLessThanOrEqual(
            self::PER_PICTURE * 31,
            $pictured - $talking - self::PICTURES,
            'a picture cost '.(($pictured - $talking) / 31).' reads',
        );
    }

    /** Once unmeasured: the first read of a process also loads the site's settings. */
    private function warm(Member $viewer): void
    {
        $this->measure($viewer, new HomeIssueMonth(2026, 7));
    }

    /**
     * @return array{int, list<array>}
     */
    private function measure(Member $viewer, HomeIssueMonth $month): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $issues = app(ListHomeIssuesInMonth::class)($month);
        ['previous' => $previous, 'next' => $next] = app(AdjacentHomeIssueMonths::class)($month);
        $payload = HomeIssueSerializer::month(
            $month,
            $issues,
            app(SummarizeHomeIssues::class)($viewer, $issues),
            $previous,
            $next,
            app(ListHomeIssueMonths::class)(),
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [count($log), $payload['days']];
    }

    /**
     * A budget met by drawing nothing is not a budget.
     *
     * @param  list<array>  $shown
     */
    private function assertFilled(array $shown, int $days, int $talking, int $pictured): void
    {
        $this->assertCount($days, $shown);

        $items = array_merge(...array_column($shown, 'items'));
        $rooms = array_filter($items, fn (array $item): bool => $item['kind'] === 'talk');
        $stories = array_filter($items, fn (array $item): bool => $item['kind'] === 'story');

        $this->assertCount($days * 3, $items);
        $this->assertCount($talking, $rooms);
        $this->assertCount($pictured, array_filter($rooms, fn (array $room): bool => $room['image'] !== null));
        $this->assertSame([], array_filter($stories, fn (array $story): bool => $story['image'] === null));
        $this->assertSame([], array_filter($rooms, fn (array $room): bool => $room['group']['imageUrl'] === null));

        foreach ($shown as $day) {
            $this->assertCount(3, $day['newcomers']);
            $this->assertCount(2, $day['newGroups']);
        }
    }

    /**
     * Every day carries every band, two pictures on each story; the first $talking days hold two
     * rooms, and on the first $pictured of those the room shown ended on a picture.
     */
    private function fill(HomeIssueMonth $month, int $days, int $talking, int $pictured): void
    {
        foreach (range(1, $days) as $number) {
            $day = $month->first()->addDays($number - 1);
            $window = HomeIssueDay::window($day);

            $issue = HomeIssue::factory()->create([
                'issue_date' => $day->toDateString(),
                'window_start' => $window->start,
                'published_at' => $window->end,
            ]);

            $this->stories($issue);

            if ($number <= $talking) {
                $this->talk($issue, $window->end, pictured: $number <= $pictured);
            }

            foreach (Member::factory()->count(3)->create() as $rank => $newcomer) {
                $this->feature($issue, HomeIssueSection::Newcomers, $newcomer, $rank + 1);
            }

            foreach ([1, 2] as $rank) {
                $this->feature($issue, HomeIssueSection::NewGroups, $this->group(), $rank);
            }

            $this->feature($issue, HomeIssueSection::UpcomingEvents, GroupEvent::factory()->create(['group_id' => $this->group()]), 1);
        }
    }

    /** Not the factory: its pool of unique company names runs out long before a month of groups does. */
    private function group(): Group
    {
        static $made = 0;

        return Group::query()->create([
            'name' => 'Group '.++$made,
            'register_policy' => JoinPolicy::Open,
            'topic_read_access' => TopicReadAccess::Everyone,
            'topic_post_authority' => TopicPostAuthority::Members,
            'file_id' => File::factory()->create()->getKey(),
        ]);
    }

    private function stories(HomeIssue $issue): void
    {
        $stories = [
            [TimelinePost::factory()->create(), TimelinePostImage::class, 'timeline_post_id'],
            [Diary::factory()->create(), DiaryImage::class, 'diary_id'],
            [GroupTopic::factory()->create(['group_id' => $this->group()]), GroupTopicImage::class, 'post_id'],
            [GroupEvent::factory()->create(['group_id' => $this->group()]), GroupEventImage::class, 'post_id'],
        ];

        // Rotated, so each kind of story leads some day of the month.
        $lead = (int) $issue->number % count($stories);
        $stories = [...array_slice($stories, $lead), ...array_slice($stories, 0, $lead)];

        foreach ($stories as $rank => [$story, $image, $key]) {
            foreach ([1, 2] as $number) {
                $image::factory()->create([$key => $story->getKey(), 'number' => $number]);
            }

            $this->feature($issue, HomeIssueSection::Stories, $story, $rank + 1);
        }
    }

    private function talk(HomeIssue $issue, CarbonImmutable $until, bool $pictured): void
    {
        foreach ([$this->group(), $this->group()] as $rank => $group) {
            $said = GroupMessage::factory()->count(2)->create([
                'group_id' => $group->getKey(),
                'created_at' => $until->subHours(3),
                'updated_at' => $until->subHours(3),
            ]);

            if ($pictured) {
                $this->attach($said->last());
            }

            $this->feature($issue, HomeIssueSection::Talk, $group, $rank + 1, [
                'since' => $until->subDay()->toIso8601String(),
                'until' => $until->toIso8601String(),
            ]);
        }
    }

    private function attach(GroupMessage $message): void
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
    }

    private function feature(HomeIssue $issue, HomeIssueSection $section, Model $source, int $rank, array $stats = []): void
    {
        HomeIssueItem::factory()->forSource($source)->create([
            'home_issue_id' => $issue->getKey(),
            'section' => $section,
            'rank' => $rank,
            'stats' => $stats,
        ]);
    }
}
