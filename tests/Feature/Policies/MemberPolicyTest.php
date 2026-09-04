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

    public function test_only_the_owner_may_manage_an_ai_account(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('manageAiAccount', $aiAccount));
        $this->assertTrue(Gate::forUser($owner->fresh())->allows('manageAiAccount', $aiAccount->fresh()));
        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('manageAiAccount', $aiAccount));
    }

    public function test_a_member_who_is_not_an_ai_account_is_never_manageable(): void
    {
        // Not even one's own row, or a member could reach their own account through the AI pages.
        $member = Member::factory()->create();

        $this->assertFalse(Gate::forUser($member)->allows('manageAiAccount', $member));
    }

    public function test_a_refusal_to_manage_hides_the_account_with_404(): void
    {
        $viewer = Member::factory()->create();
        $theirs = Member::factory()->aiAccount()->create();

        $response = Gate::forUser($viewer)->inspect('manageAiAccount', $theirs);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
