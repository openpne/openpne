<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\NewGroupCandidates;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\HomeIssueItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NewGroupCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    private function window(): HomeIssueWindow
    {
        $end = CarbonImmutable::parse(self::NOW);

        return new HomeIssueWindow($end->subDay(), $end);
    }

    private function founded(CarbonImmutable $at, int $members = 0): Group
    {
        Carbon::setTestNow($at);

        try {
            $group = Group::factory()->create();
        } finally {
            Carbon::setTestNow();
        }
        GroupMember::factory()->count($members)->create(['group_id' => $group->getKey()]);

        return $group;
    }

    /**
     * @param  Collection<int, PlannedItem>  $items
     * @return list<int>
     */
    private function ids($items): array
    {
        return $items->map(fn (PlannedItem $item): int => $item->sourceId)->values()->all();
    }

    public function test_groups_founded_inside_the_window_are_offered_newest_first_with_their_size_and_cut_to_the_limit(): void
    {
        $window = $this->window();
        // Made newest first on purpose: an id order alone would put them backwards.
        $this->founded($window->end->addSecond());
        $newest = $this->founded($window->end);
        $middle = $this->founded($window->end->subHour(), 2);
        $oldest = $this->founded($window->start->addSecond());
        $this->founded($window->start);

        $items = app(NewGroupCandidates::class)($window, 10);

        $this->assertSame([$newest->getKey(), $middle->getKey(), $oldest->getKey()], $this->ids($items));
        $this->assertSame(['members' => 2], $items->get(1)?->stats);
        $this->assertSame(0, $items->first()?->score);
        $this->assertSame([$newest->getKey(), $middle->getKey()], $this->ids(app(NewGroupCandidates::class)($window, 2)));
    }

    public function test_a_group_an_issue_already_introduced_is_not_offered_again(): void
    {
        $window = $this->window();
        $fresh = $this->founded($window->end);
        $introduced = $this->founded($window->end);
        HomeIssueItem::factory()->create([
            'section' => HomeIssueSection::NewGroups,
            'source_type' => app(NewGroupCandidates::class)->alias(),
            'source_id' => $introduced->getKey(),
        ]);
        // A talk burst about the same group is another section's row.
        HomeIssueItem::factory()->create([
            'section' => HomeIssueSection::Talk,
            'source_type' => app(NewGroupCandidates::class)->alias(),
            'source_id' => $fresh->getKey(),
        ]);

        $this->assertSame([$fresh->getKey()], $this->ids(app(NewGroupCandidates::class)($window, 10)));
    }
}
