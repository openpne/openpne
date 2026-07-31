<?php

namespace Tests\Feature\Member\Queries;

use App\Features\Member\Queries\RandomMembers;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RandomMembersTest extends TestCase
{
    use RefreshDatabase;

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $blocked->getKey()]);
    }

    public function test_returns_other_members_and_never_the_viewer(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        $result = (new RandomMembers)($viewer);

        $this->assertSame([$other->getKey()], $result->pluck('id')->all());
    }

    public function test_excludes_a_member_who_blocks_the_viewer(): void
    {
        $viewer = Member::factory()->create();
        $blocker = Member::factory()->create();
        $this->block($blocker, $viewer);

        $this->assertNotContains($blocker->getKey(), (new RandomMembers)($viewer)->pluck('id')->all());
    }

    /** The block is one-directional, as in SearchMembers: it hides the blocker from the blocked, not the reverse. */
    public function test_includes_a_member_the_viewer_blocks(): void
    {
        $viewer = Member::factory()->create();
        $blocked = Member::factory()->create();
        $this->block($viewer, $blocked);

        $this->assertContains($blocked->getKey(), (new RandomMembers)($viewer)->pluck('id')->all());
    }

    /** is_login_rejected gates logging in and receiving, not being seen (as in SearchMembers). */
    public function test_includes_a_member_whose_login_is_rejected(): void
    {
        $viewer = Member::factory()->create();
        $rejected = Member::factory()->create();
        $rejected->forceFill(['is_login_rejected' => true])->save();

        $this->assertContains($rejected->getKey(), (new RandomMembers)($viewer)->pluck('id')->all());
    }

    public function test_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->count(12)->create();

        $this->assertCount(9, (new RandomMembers)($viewer));
        $this->assertCount(3, (new RandomMembers)($viewer, 3));
    }

    public function test_is_empty_on_a_one_member_sns(): void
    {
        $this->assertCount(0, (new RandomMembers)(Member::factory()->create()));
    }
}
