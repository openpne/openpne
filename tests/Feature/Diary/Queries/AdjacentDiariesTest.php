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

    public function test_returns_the_neighbors_adjacent_by_id_in_the_authors_timeline(): void
    {
        $owner = Member::factory()->create();
        $older = $this->diary($owner);
        $current = $this->diary($owner);
        $newer = $this->diary($owner);

        ['previous' => $previous, 'next' => $next] = (new AdjacentDiaries)($owner, $current);

        // previous is the older entry (smaller id), next the newer.
        $this->assertSame($older->getKey(), $previous?->getKey());
        $this->assertSame($newer->getKey(), $next?->getKey());
    }

    public function test_endpoints_have_only_one_neighbor(): void
    {
        $owner = Member::factory()->create();
        $first = $this->diary($owner);
        $last = $this->diary($owner);

        $this->assertNull((new AdjacentDiaries)($owner, $first)['previous']);
        $this->assertSame($last->getKey(), (new AdjacentDiaries)($owner, $first)['next']?->getKey());
        $this->assertSame($first->getKey(), (new AdjacentDiaries)($owner, $last)['previous']?->getKey());
        $this->assertNull((new AdjacentDiaries)($owner, $last)['next']);
    }

    public function test_skips_neighbors_the_viewer_may_not_see(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $visibleOlder = $this->diary($owner, Visibility::Members);
        $this->diary($owner, Visibility::Private); // hidden from a non-friend
        $current = $this->diary($owner, Visibility::Members);
        $this->diary($owner, Visibility::Private); // hidden from a non-friend
        $visibleNewer = $this->diary($owner, Visibility::Members);

        ['previous' => $previous, 'next' => $next] = (new AdjacentDiaries)($other, $current);

        // The private entries on either side are skipped; adjacency lands on the visible ones.
        $this->assertSame($visibleOlder->getKey(), $previous?->getKey());
        $this->assertSame($visibleNewer->getKey(), $next?->getKey());
    }

    public function test_does_not_cross_into_another_authors_diaries(): void
    {
        [$author, $stranger] = Member::factory()->count(2)->create()->all();
        $own = $this->diary($author, Visibility::Members);
        $this->diary($stranger, Visibility::Members); // newer id, different author

        $this->assertNull((new AdjacentDiaries)($author, $own)['next']);
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

        $this->assertNull($result['previous']);
        $this->assertNull($result['next']);
    }

    private function diary(Member $owner, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => $visibility]);
    }
}
