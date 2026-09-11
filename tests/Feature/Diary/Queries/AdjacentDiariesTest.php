<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\AdjacentDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdjacentDiariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_neighbors_adjacent_in_the_archives_created_at_then_id_order(): void
    {
        $owner = Member::factory()->create();
        $older = $this->diary($owner);
        $current = $this->diary($owner);
        $newer = $this->diary($owner);

        ['older' => $olderNeighbor, 'newer' => $newerNeighbor] = (new AdjacentDiaries)($owner, $current);

        $this->assertSame($older->getKey(), $olderNeighbor?->getKey());
        $this->assertSame($newer->getKey(), $newerNeighbor?->getKey());
    }

    public function test_a_backdated_entry_neighbors_by_its_date_not_its_id(): void
    {
        $owner = Member::factory()->create();
        $first = $this->diary($owner, createdAt: '2026-03-01 10:00:00');
        $third = $this->diary($owner, createdAt: '2026-03-03 10:00:00');
        $second = $this->diary($owner, createdAt: '2026-03-02 10:00:00');

        ['older' => $older, 'newer' => $newer] = (new AdjacentDiaries)($owner, $second);

        $this->assertSame($first->getKey(), $older?->getKey());
        $this->assertSame($third->getKey(), $newer?->getKey());
    }

    public function test_a_shared_second_neighbors_by_id(): void
    {
        $owner = Member::factory()->create();
        $a = $this->diary($owner, createdAt: '2026-03-01 10:00:00');
        $b = $this->diary($owner, createdAt: '2026-03-01 10:00:00');
        $c = $this->diary($owner, createdAt: '2026-03-01 10:00:00');

        ['older' => $older, 'newer' => $newer] = (new AdjacentDiaries)($owner, $b);

        $this->assertSame($a->getKey(), $older?->getKey());
        $this->assertSame($c->getKey(), $newer?->getKey());
    }

    public function test_an_entry_with_no_time_has_no_neighbors(): void
    {
        $owner = Member::factory()->create();
        $this->diary($owner);
        $timeless = $this->diary($owner);
        $this->diary($owner);
        DB::table('diaries')->where('id', $timeless->getKey())->update(['created_at' => null]);

        $this->assertSame(['older' => null, 'newer' => null], (new AdjacentDiaries)($owner, $timeless->fresh()));
    }

    public function test_endpoints_have_only_one_neighbor(): void
    {
        $owner = Member::factory()->create();
        $first = $this->diary($owner);
        $last = $this->diary($owner);

        $this->assertNull((new AdjacentDiaries)($owner, $first)['older']);
        $this->assertSame($last->getKey(), (new AdjacentDiaries)($owner, $first)['newer']?->getKey());
        $this->assertSame($first->getKey(), (new AdjacentDiaries)($owner, $last)['older']?->getKey());
        $this->assertNull((new AdjacentDiaries)($owner, $last)['newer']);
    }

    public function test_skips_neighbors_the_viewer_may_not_see(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $visibleOlder = $this->diary($owner, Visibility::Members);
        $this->diary($owner, Visibility::Private); // hidden from a non-friend
        $current = $this->diary($owner, Visibility::Members);
        $this->diary($owner, Visibility::Private); // hidden from a non-friend
        $visibleNewer = $this->diary($owner, Visibility::Members);

        ['older' => $older, 'newer' => $newer] = (new AdjacentDiaries)($other, $current);

        // The private entries on either side are skipped; adjacency lands on the visible ones.
        $this->assertSame($visibleOlder->getKey(), $older?->getKey());
        $this->assertSame($visibleNewer->getKey(), $newer?->getKey());
    }

    public function test_does_not_cross_into_another_authors_diaries(): void
    {
        [$author, $stranger] = Member::factory()->count(2)->create()->all();
        $own = $this->diary($author, Visibility::Members);
        $this->diary($stranger, Visibility::Members); // newer id, different author

        $this->assertNull((new AdjacentDiaries)($author, $own)['newer']);
    }

    public function test_blocked_viewer_gets_no_neighbors(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->diary($owner, Visibility::Members);
        $current = $this->diary($owner, Visibility::Members);
        $this->diary($owner, Visibility::Members);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $result = (new AdjacentDiaries)($viewer, $current);

        $this->assertNull($result['older']);
        $this->assertNull($result['newer']);
    }

    private function diary(Member $owner, Visibility $visibility = Visibility::Members, ?string $createdAt = null): Diary
    {
        $attrs = ['member_id' => $owner->getKey(), 'visibility' => $visibility];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return Diary::factory()->create($attrs);
    }
}
