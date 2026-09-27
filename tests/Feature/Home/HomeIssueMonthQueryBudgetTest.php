<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueMonth;
use App\Features\Home\HomeIssueSection;
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
 * A month costs what a day of it costs, a count per issue's talk excepted
 * (docs/internals/home-issues.md, "The month page").
 */
class HomeIssueMonthQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Measured at 53 for 31 issues: 24 reads and 29 talk counts. The margin stays below the smallest
     * per-row loop the fixture could hide, a read per issue, which would add 31.
     */
    private const CEILING = 57;

    public function test_a_month_costs_the_same_reads_however_many_issues_it_holds(): void
    {
        Carbon::setTestNow('2026-09-01 06:00:00');

        $viewer = Member::factory()->create();

        $this->fill(new HomeIssueMonth(2026, 6), 15);
        $this->fill(new HomeIssueMonth(2026, 7), 31);

        // Once unmeasured: the first read of a request also loads the site's settings.
        $this->measure($viewer, new HomeIssueMonth(2026, 6));

        [$half, $halfDays] = $this->measure($viewer, new HomeIssueMonth(2026, 6));
        [$full, $fullDays] = $this->measure($viewer, new HomeIssueMonth(2026, 7));

        // The fixture really did fill the rows — a budget met by rendering nothing is not a budget.
        $this->assertCount(15, $halfDays);
        $this->assertCount(31, $fullDays);
        foreach ($fullDays as $day) {
            $this->assertNotNull($day['top'], "{$day['date']} drew nothing");
        }
        $this->assertSame(
            ['newGroup', 'newcomer', 'talk', 'story'],
            array_values(array_unique(array_column(array_column($fullDays, 'top'), 'kind'))),
        );

        $this->assertSame($half['other'], $full['other'], 'a read outside talk grew with the month');
        $this->assertLessThanOrEqual(31, $full['talk']);
        $this->assertLessThanOrEqual(self::CEILING, $full['talk'] + $full['other'], 'a full month cost '.($full['talk'] + $full['other']).' queries');
    }

    /**
     * @return array{array{talk: int, other: int}, list<array>}
     */
    private function measure(Member $viewer, HomeIssueMonth $month): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $issues = app(ListHomeIssuesInMonth::class)($month);
        $payload = HomeIssueSerializer::month($month, $issues, app(SummarizeHomeIssues::class)($viewer, $issues), null, null);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $talk = count(array_filter($log, fn (array $query): bool => str_contains($query['query'], 'group_messages')));

        return [['talk' => $talk, 'other' => count($log) - $talk], $payload['days']];
    }

    /**
     * The newest three days lead with each fallback in turn; every other day carries every band, with
     * two pictures on each story.
     */
    private function fill(HomeIssueMonth $month, int $days): void
    {
        foreach (range(1, $days) as $number) {
            $day = $month->first()->addDays($number - 1);
            $window = HomeIssueDay::window($day);

            $issue = HomeIssue::factory()->create([
                'issue_date' => $day->toDateString(),
                'window_start' => $window->start,
                'published_at' => $window->end,
            ]);

            $fallback = $days - $number;

            if ($fallback > 2) {
                $this->stories($issue);
            }

            if ($fallback > 1) {
                $this->talk($issue, $window->end);
            }

            if ($fallback > 0) {
                foreach (Member::factory()->count(3)->create() as $rank => $newcomer) {
                    $this->feature($issue, HomeIssueSection::Newcomers, $newcomer, $rank + 1);
                }
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

    private function talk(HomeIssue $issue, CarbonImmutable $until): void
    {
        foreach ([$this->group(), $this->group()] as $rank => $group) {
            GroupMessage::factory()->count(2)->create([
                'group_id' => $group->getKey(),
                'created_at' => $until->subHours(3),
                'updated_at' => $until->subHours(3),
            ]);

            $this->feature($issue, HomeIssueSection::Talk, $group, $rank + 1, [
                'since' => $until->subDay()->toIso8601String(),
                'until' => $until->toIso8601String(),
            ]);
        }
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
