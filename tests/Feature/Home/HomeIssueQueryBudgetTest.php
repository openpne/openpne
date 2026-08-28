<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A full issue costs a bounded number of reads.
 *
 * The page is bounded by the ledger's own caps, so the reads have to be bounded by the number of
 * SOURCE TABLES rather than by the number of rows — one read per table, plus one per relation the
 * eager loads name, plus one apiece for the blocks and friendships the gate asks about. Talk is the
 * exception the design accepts: a burst is a stretch of one room, and there is no read that
 * describes several rooms at once.
 */
class HomeIssueQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    /**
     * Measured at 47 against the fixture below, with a little headroom for a relation an eager load
     * skips here because the fixture leaves it null (an avatar's file).
     *
     * The shape of it: one read for the ledger and one apiece for the blocks and the friendships;
     * one per source table and one per relation its eager loads name; three per burst — the anchor,
     * the count and the excerpt — plus the excerpt's own author, picture and mention loads, which is
     * the per-room cost talk's digest is by design.
     *
     * What the number guards is that none of it grows with the ROW counts, so the margin is kept
     * BELOW the smallest per-item loop that could appear: the fixture fills every band to its cap,
     * and one read per story (8), per face (12) or per group (6) each puts it past this.
     */
    private const CEILING = 51;

    public function test_a_full_issue_costs_a_bounded_number_of_reads(): void
    {
        Carbon::setTestNow(self::NOW);

        $now = CarbonImmutable::parse(self::NOW);
        $issue = HomeIssue::factory()->create([
            'number' => 1,
            'issue_date' => $now->toDateString(),
            'window_start' => $now->subDay(),
            'published_at' => $now,
        ]);

        // A stranger: not the owner of anything here, friends with nobody, in none of the groups.
        $viewer = Member::factory()->create();

        $rank = 0;
        foreach ([TimelinePost::class, Diary::class, GroupTopic::class, GroupEvent::class] as $model) {
            foreach (range(1, 2) as $ignored) {
                $this->feature($issue, HomeIssueSection::Stories, $model::factory()->create(), ++$rank);
            }
        }

        $rank = 0;
        foreach (range(1, 3) as $ignored) {
            $group = Group::factory()->create();
            GroupMessage::factory()->count(4)->create([
                'group_id' => $group->getKey(),
                'created_at' => $now->subHours(3),
                'updated_at' => $now->subHours(3),
            ]);
            $this->feature($issue, HomeIssueSection::Talk, $group, ++$rank, [
                'since' => $now->subDay()->toIso8601String(),
                'until' => $now->toIso8601String(),
            ]);
        }

        $rank = 0;
        foreach (Member::factory()->count(12)->create() as $newcomer) {
            $this->feature($issue, HomeIssueSection::Newcomers, $newcomer, ++$rank);
        }

        $rank = 0;
        foreach (Group::factory()->count(6)->create() as $group) {
            $this->feature($issue, HomeIssueSection::NewGroups, $group, ++$rank);
        }

        $rank = 0;
        foreach (range(1, 6) as $ignored) {
            $this->feature($issue, HomeIssueSection::UpcomingEvents, GroupEvent::factory()->create(), ++$rank);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = HomeIssueSerializer::page(
            $issue,
            app(ShowHomeIssue::class)($viewer, $issue),
            null,
            null,
            $now,
        );
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The fixture really did fill every band — a budget met by rendering nothing is not a budget.
        $this->assertCount(8, $payload['issue']['stories']);
        $this->assertCount(3, $payload['issue']['talkBursts']);
        $this->assertCount(12, $payload['issue']['newcomers']);
        $this->assertCount(6, $payload['issue']['newGroups']);
        $this->assertCount(6, $payload['issue']['upcomingEvents']);

        $this->assertLessThanOrEqual(self::CEILING, $queries, "a full issue cost {$queries} queries");
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
