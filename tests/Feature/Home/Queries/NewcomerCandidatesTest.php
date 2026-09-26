<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\NewcomerCandidates;
use App\Models\HomeIssueItem;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NewcomerCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    private function window(): HomeIssueWindow
    {
        $end = CarbonImmutable::parse(self::NOW);

        return new HomeIssueWindow($end->subDay(), $end);
    }

    private function joinedAt(CarbonImmutable $at): Member
    {
        Carbon::setTestNow($at);

        try {
            return Member::factory()->create();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  Collection<int, PlannedItem>  $items
     * @return list<int>
     */
    private function ids($items): array
    {
        return $items->map(fn (PlannedItem $item): int => $item->sourceId)->values()->all();
    }

    public function test_members_who_joined_inside_the_window_are_offered_newest_first_and_cut_to_the_limit(): void
    {
        $window = $this->window();
        $this->joinedAt($window->start);
        $oldest = $this->joinedAt($window->start->addSecond());
        $middle = $this->joinedAt($window->end->subHour());
        $newest = $this->joinedAt($window->end);
        $this->joinedAt($window->end->addSecond());

        $items = app(NewcomerCandidates::class)($window, 2);

        $this->assertSame([$newest->getKey(), $middle->getKey()], $this->ids($items));
        $this->assertNotContains($oldest->getKey(), $this->ids($items));
        $this->assertTrue($window->end->equalTo($items->first()?->createdAt));
    }

    public function test_a_member_an_issue_already_welcomed_is_not_offered_again(): void
    {
        $window = $this->window();
        $fresh = $this->joinedAt($window->end);
        $welcomed = $this->joinedAt($window->end);
        HomeIssueItem::factory()->create([
            'section' => HomeIssueSection::Newcomers,
            'source_type' => app(NewcomerCandidates::class)->alias(),
            'source_id' => $welcomed->getKey(),
        ]);

        $this->assertSame([$fresh->getKey()], $this->ids(app(NewcomerCandidates::class)($window, 10)));
    }
}
