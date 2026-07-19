<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\DropMember;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class DropMemberTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_an_admin_drops_a_plain_member(): void
    {
        [$community, $admin, $member] = $this->communityWith('admin', 'member');

        (new DropMember)($admin, $community, $member);

        $this->assertDatabaseMissing('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_a_sub_admin_drops_a_plain_member(): void
    {
        [$community, $sub, $member] = $this->communityWith('subAdmin', 'member');

        (new DropMember)($sub, $community, $member);

        $this->assertDatabaseMissing('community_members', ['member_id' => $member->getKey()]);
    }

    public function test_a_plain_member_cannot_drop(): void
    {
        [$community, $actor, $member] = $this->communityWith('member', 'member');

        $this->assertFailsWith(CommunityActionFailure::NotManager, fn () => (new DropMember)($actor, $community, $member));
        $this->assertDatabaseHas('community_members', ['member_id' => $member->getKey()]);
    }

    public function test_dropping_a_non_member_fails(): void
    {
        [$community, $admin] = $this->communityWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new DropMember)($admin, $community, $stranger));
    }

    public function test_dropping_an_admin_or_sub_admin_fails_as_not_plain(): void
    {
        [$community, $admin, $sub] = $this->communityWith('admin', 'subAdmin');

        $this->assertFailsWith(CommunityActionFailure::TargetNotPlainMember, fn () => (new DropMember)($admin, $community, $sub));
    }

    public function test_an_admin_cannot_drop_themselves(): void
    {
        [$community, $admin] = $this->communityWith('admin');

        $this->assertFailsWith(CommunityActionFailure::TargetNotPlainMember, fn () => (new DropMember)($admin, $community, $admin));
        $this->assertDatabaseHas('community_members', ['member_id' => $admin->getKey()]);
    }

    public function test_dropping_the_pending_nominee_clears_the_transfer(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        (new DropMember)($admin, $community, $nominee);

        $this->assertDatabaseMissing('community_members', ['member_id' => $nominee->getKey()]);
        $this->assertNull($community->fresh()->pending_admin_member_id);
    }

    /**
     * @return array{0: Community, ...}
     */
    private function communityWith(string ...$roles): array
    {
        $community = Community::factory()->create();
        $out = [$community];

        foreach ($roles as $role) {
            $member = Member::factory()->create();
            CommunityMember::factory()->{$role}()->create([
                'community_id' => $community->getKey(),
                'member_id' => $member->getKey(),
            ]);
            $out[] = $member;
        }

        return $out;
    }
}
