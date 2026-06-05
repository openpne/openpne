<?php

namespace Tests\Feature\Policies;

use App\Models\Member;
use App\Policies\MemberPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * MemberPolicy::access — the page-level block gate. It denies (404) only when the subject has
 * blocked the viewer; everything else (self, unrelated, guest, reverse-direction block) is
 * allowed, since this gates reachability, not profile-field visibility.
 */
class MemberPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_access_their_own_pages(): void
    {
        $member = Member::factory()->create();

        $this->assertTrue(Gate::forUser($member)->allows('access', $member));
    }

    public function test_member_can_access_an_unrelated_members_pages(): void
    {
        [$viewer, $subject] = Member::factory()->count(2)->create()->all();

        $this->assertTrue(Gate::forUser($viewer)->allows('access', $subject));
    }

    public function test_blocked_viewer_is_denied_with_404(): void
    {
        [$viewer, $subject] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert(['blocker_id' => $subject->getKey(), 'blocked_id' => $viewer->getKey()]);

        $response = Gate::forUser($viewer)->inspect('access', $subject);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_block_is_one_way_the_blocker_can_still_reach_whom_they_blocked(): void
    {
        // The gate hides the SUBJECT's pages from someone the SUBJECT blocked, not the reverse.
        [$viewer, $subject] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert(['blocker_id' => $viewer->getKey(), 'blocked_id' => $subject->getKey()]);

        $this->assertTrue(Gate::forUser($viewer)->allows('access', $subject));
    }

    public function test_guest_is_allowed(): void
    {
        $subject = Member::factory()->create();

        $this->assertTrue((new MemberPolicy)->access(null, $subject)->allowed());
    }
}
