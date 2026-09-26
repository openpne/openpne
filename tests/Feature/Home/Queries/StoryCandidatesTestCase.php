<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\StoryCandidates;
use App\Models\HomeIssueItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** The StoryCandidates contract, run once per kind; a kind's own ranking rule is its subclass's business. */
abstract class StoryCandidatesTestCase extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    abstract protected function candidates(): StoryCandidates;

    /** A row every member may read, created at $at, carrying $engagement of what this kind ranks by. */
    abstract protected function readable(CarbonImmutable $at, int $engagement = 0): Model;

    /** A row created at $at that not every member may read. */
    abstract protected function walledOff(CarbonImmutable $at): Model;

    protected function window(): HomeIssueWindow
    {
        $end = CarbonImmutable::parse(self::NOW);

        return new HomeIssueWindow($end->subDay(), $end);
    }

    protected function at(CarbonImmutable $when, callable $make): mixed
    {
        Carbon::setTestNow($when);

        try {
            return $make();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  Collection<int, PlannedItem>  $items
     * @return list<int>
     */
    protected function ids(Collection $items): array
    {
        return $items->map(fn (PlannedItem $item): int => $item->sourceId)->values()->all();
    }

    protected function featured(Model $row, ?string $alias = null): void
    {
        HomeIssueItem::factory()->create([
            'section' => HomeIssueSection::Stories,
            'source_type' => $alias ?? $this->candidates()->alias(),
            'source_id' => $row->getKey(),
        ]);
    }

    public function test_only_rows_inside_the_window_are_offered_and_the_window_is_open_at_its_start(): void
    {
        $window = $this->window();
        $this->readable($window->start);
        $first = $this->readable($window->start->addSecond());
        $last = $this->readable($window->end);
        $this->readable($window->end->addSecond());

        $offered = $this->ids($this->candidates()($window, 10));

        $this->assertEqualsCanonicalizing([$first->getKey(), $last->getKey()], $offered);
    }

    public function test_what_an_issue_already_featured_is_not_offered_again(): void
    {
        $window = $this->window();
        $fresh = $this->readable($window->end);
        $featured = $this->readable($window->end);
        $this->featured($featured);
        // The same id under another kind is not this kind's feature.
        $this->featured($fresh, 'some-other-kind');

        $this->assertSame([$fresh->getKey()], $this->ids($this->candidates()($window, 10)));
    }

    public function test_a_row_not_every_member_may_read_is_neither_offered_nor_pinnable(): void
    {
        $window = $this->window();
        $open = $this->readable($window->end);
        $walled = $this->walledOff($window->end);

        $this->assertSame([$open->getKey()], $this->ids($this->candidates()($window, 10)));
        $this->assertNull($this->candidates()->find($walled->getKey()));
        $this->assertSame($open->getKey(), $this->candidates()->find($open->getKey())?->sourceId);
    }

    public function test_ranked_by_engagement_then_recency_and_cut_to_the_limit(): void
    {
        $window = $this->window();
        // Made in the wrong order on purpose: an id tiebreak alone would rank the older row first.
        $quietNew = $this->readable($window->end->subHour());
        $quietOld = $this->readable($window->end->subHours(3));
        $busy = $this->readable($window->end->subHours(2), 2);
        $lively = $this->readable($window->end->subHours(4), 1);

        $items = $this->candidates()($window, 3);

        $this->assertSame([$busy->getKey(), $lively->getKey(), $quietNew->getKey()], $this->ids($items));
        $this->assertNotContains($quietOld->getKey(), $this->ids($items));
        $this->assertSame(2, $items->first()?->score);
        $this->assertTrue($window->end->subHours(2)->equalTo($items->first()?->createdAt));
    }

    public function test_a_pin_is_held_to_neither_the_window_nor_the_ledger(): void
    {
        $window = $this->window();
        $old = $this->readable($window->start->subDay(), 1);
        $this->featured($old);

        $pinned = $this->candidates()->find($old->getKey());

        $this->assertSame($old->getKey(), $pinned?->sourceId);
        $this->assertSame(1, $pinned?->score);
    }
}
