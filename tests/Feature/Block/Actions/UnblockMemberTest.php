<?php

namespace Tests\Feature\Block\Actions;

use App\Features\Block\Actions\UnblockMember;
use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnblockMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_block_row(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        (new UnblockMember)($blocker, $target);

        $this->assertDatabaseMissing('member_blocks', [
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_rejects_when_not_blocked(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();

        try {
            (new UnblockMember)($blocker, $target);
            $this->fail('Expected BlockActionException');
        } catch (BlockActionException $e) {
            $this->assertSame(BlockActionFailure::NotBlocked, $e->reason);
        }
    }

    public function test_rejects_self_unblock(): void
    {
        $member = Member::factory()->create();

        $this->expectException(BlockActionException::class);
        (new UnblockMember)($member, $member);
    }

    public function test_does_not_restore_friendship(): void
    {
        $blocker = Member::factory()->create();
        $target = Member::factory()->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        (new UnblockMember)($blocker, $target);

        $this->assertDatabaseMissing('friendships', [
            'member_id' => $blocker->getKey(),
            'friend_id' => $target->getKey(),
        ]);
    }
}
