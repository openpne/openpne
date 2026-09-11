<?php

namespace Tests\Feature\Member\Queries;

use App\Features\Member\Queries\SearchMembers;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\Support\PinsOrderBy;
use Tests\TestCase;

class SearchMembersTiebreakTest extends TestCase
{
    use PinsOrderBy;
    use RefreshDatabase;

    public function test_two_pages_of_one_second_split_at_id_with_no_row_repeated_or_lost(): void
    {
        $viewer = Member::factory()->create();
        $ids = Member::factory()->count(25)->create(['created_at' => '2026-03-01 12:00:00'])->modelKeys();
        $expected = array_reverse($ids);

        $first = collect($this->search($viewer, 1)->items())->map->getKey()->all();
        $second = collect($this->search($viewer, 2)->items())->map->getKey()->all();

        // The viewer joined in this request, so it leads the newest-first list.
        $this->assertSame([$viewer->getKey(), ...array_slice($expected, 0, 19)], $first);
        $this->assertSame(array_slice($expected, 19), $second);
    }

    public function test_the_page_orders_by_created_at_then_id(): void
    {
        $viewer = Member::factory()->create();

        $orders = $this->orderClausesOn('members', fn () => $this->search($viewer, 1));

        $this->assertSame(['order by created_at desc, id desc'], array_values(array_unique($orders)));
    }

    private function search(Member $viewer, int $page)
    {
        return $this->onPage($page, fn () => app(SearchMembers::class)($viewer, '', [], [], [], null, 20));
    }

    private function onPage(int $page, callable $run): mixed
    {
        Paginator::currentPageResolver(fn () => $page);
        try {
            return $run();
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
    }
}
