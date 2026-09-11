<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\ListDiaries;
use App\Features\Diary\Queries\ListFriendDiaries;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Diary\Queries\SearchDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryListTiebreakTest extends TestCase
{
    use RefreshDatabase;

    private Member $viewer;

    private Member $owner;

    /** @var list<int> ids newest-first, all sharing one created_at */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = Member::factory()->create();
        $this->owner = Member::factory()->create();
        $this->viewer->friendships()->attach($this->owner);
        $this->owner->friendships()->attach($this->viewer);

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = Diary::factory()->create([
                'member_id' => $this->owner->getKey(),
                'visibility' => Visibility::Members,
                'title' => 'same second',
                'created_at' => '2026-03-01 12:00:00',
            ])->getKey();
        }
        $this->ids = array_reverse($ids);
    }

    public function test_two_pages_of_one_second_split_at_id_with_no_row_repeated_or_lost(): void
    {
        $lists = [
            'recent feed' => fn () => (new ListRecentDiaries)($this->viewer, 20),
            'friend feed' => fn () => (new ListFriendDiaries)($this->viewer, 20),
            'search' => fn () => (new SearchDiaries)($this->viewer, 'same', 20),
            'archive' => fn () => (new ListDiaries)($this->viewer, $this->owner, 20),
        ];

        foreach ($lists as $name => $list) {
            $first = collect($this->onPage(1, $list)->items())->map->getKey()->all();
            $second = collect($this->onPage(2, $list)->items())->map->getKey()->all();

            $this->assertSame(array_slice($this->ids, 0, 20), $first, $name);
            $this->assertSame(array_slice($this->ids, 20), $second, $name);
        }
    }

    public function test_the_unpaged_takes_cut_the_second_at_id(): void
    {
        $top = array_slice($this->ids, 0, 20);

        $this->assertSame($top, (new RecentMemberDiaries)($this->viewer, $this->owner, 20)->map->getKey()->all());
        $this->assertSame($top, (new ListRecentDiaries)->take($this->viewer, 20)->map->getKey()->all());
        $this->assertSame($top, (new ListFriendDiaries)->take($this->viewer, 20)->map->getKey()->all());
    }

    /** The member-scoped lists read the (member_id, created_at) index, which already yields id order within a tie, so for them only this SQL pin goes red. */
    public function test_every_list_orders_by_created_at_then_id(): void
    {
        DB::enableQueryLog();
        try {
            (new ListRecentDiaries)($this->viewer);
            (new ListRecentDiaries)->take($this->viewer, 5);
            (new ListFriendDiaries)($this->viewer);
            (new ListFriendDiaries)->take($this->viewer, 5);
            (new SearchDiaries)($this->viewer, 'same');
            (new ListDiaries)($this->viewer, $this->owner);
            (new RecentMemberDiaries)($this->viewer, $this->owner);
            $orders = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql) => preg_match('/from [`"]diaries[`"] .* order by/', $sql) === 1)
                ->map(fn (string $sql) => preg_replace('/ limit .*$/', '', preg_replace('/[`"]/', '', substr($sql, strpos($sql, 'order by')))))
                ->values()->all();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(7, $orders);
        $this->assertSame(['order by created_at desc, id desc'], array_values(array_unique($orders)));
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
