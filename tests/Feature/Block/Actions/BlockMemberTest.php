<?php

namespace Tests\Feature\Block\Actions;

use App\Features\Block\Actions\BlockMember;
use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BlockMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_block_row(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();

        (new BlockMember)($blocker, $target);

        $this->assertDatabaseHas('member_blocks', [
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_rejects_self_block(): void
    {
        $member = Member::factory()->create();

        try {
            (new BlockMember)($member, $member);
            $this->fail('Expected BlockActionException');
        } catch (BlockActionException $e) {
            $this->assertSame(BlockActionFailure::SelfBlock, $e->reason);
        }
    }

    public function test_rejects_when_already_blocked(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        try {
            (new BlockMember)($blocker, $target);
            $this->fail('Expected BlockActionException');
        } catch (BlockActionException $e) {
            $this->assertSame(BlockActionFailure::AlreadyBlocked, $e->reason);
        }
    }

    public function test_severs_existing_friendship(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $blocker->getKey(), 'friend_id' => $target->getKey()],
            ['member_id' => $target->getKey(), 'friend_id' => $blocker->getKey()],
        ]);

        (new BlockMember)($blocker, $target);

        $this->assertDatabaseMissing('friendships', [
            'member_id' => $blocker->getKey(),
            'friend_id' => $target->getKey(),
        ]);
        $this->assertDatabaseMissing('friendships', [
            'member_id' => $target->getKey(),
            'friend_id' => $blocker->getKey(),
        ]);
    }

    public function test_cancels_pending_requests_in_both_directions(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('friend_requests')->insert([
            ['requester_id' => $blocker->getKey(), 'target_id' => $target->getKey()],
            ['requester_id' => $target->getKey(), 'target_id' => $blocker->getKey()],
        ]);

        (new BlockMember)($blocker, $target);

        $this->assertDatabaseMissing('friend_requests', [
            'requester_id' => $blocker->getKey(),
            'target_id' => $target->getKey(),
        ]);
        $this->assertDatabaseMissing('friend_requests', [
            'requester_id' => $target->getKey(),
            'target_id' => $blocker->getKey(),
        ]);
    }
}
