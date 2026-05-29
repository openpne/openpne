<?php

namespace Tests\Feature\Block\Queries;

use App\Features\Block\Queries\ListBlocks;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
