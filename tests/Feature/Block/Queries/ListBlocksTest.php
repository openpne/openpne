<?php

namespace Tests\Feature\Block\Queries;

use App\Features\Block\Queries\ListBlocks;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_own_blocks(): void
    {
        $blocker = Member::factory()->create();
        $blockedA = Member::factory()->create();
        $blockedB = Member::factory()->create();
        DB::table('member_blocks')->insert([
            ['blocker_id' => $blocker->getKey(), 'blocked_id' => $blockedA->getKey()],
            ['blocker_id' => $blocker->getKey(), 'blocked_id' => $blockedB->getKey()],
        ]);

        $result = (new ListBlocks)($blocker);

        $this->assertCount(2, $result->items());
    }

    public function test_two_pages_of_one_second_split_at_the_blocked_id_with_no_row_repeated_or_lost(): void
    {
        $blocker = Member::factory()->create();
        $blocked = Member::factory()->count(25)->create();
        DB::table('member_blocks')->insert($blocked->map(fn (Member $m) => [
            'blocker_id' => $blocker->getKey(), 'blocked_id' => $m->getKey(), 'created_at' => '2026-03-01 12:00:00',
        ])->all());
        $expected = array_reverse($blocked->modelKeys());

        $first = $this->onPage(1, fn () => (new ListBlocks)($blocker))->getCollection()->modelKeys();
        $second = $this->onPage(2, fn () => (new ListBlocks)($blocker))->getCollection()->modelKeys();

        $this->assertSame(array_slice($expected, 0, 20), $first);
        $this->assertSame(array_slice($expected, 20), $second);
    }

    public function test_excludes_other_members_blocks(): void
    {
        $blocker = Member::factory()->create();
        $other = Member::factory()->create();
        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $other->getKey(),
            'blocked_id' => $blocked->getKey(),
        ]);

        $result = (new ListBlocks)($blocker);

        $this->assertCount(0, $result->items());
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
