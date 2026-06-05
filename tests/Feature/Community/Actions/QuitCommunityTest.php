<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\QuitCommunity;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class QuitCommunityTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_a_member_can_quit(): void
    {
        $community = Community::factory()->create();
        $member = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        (new QuitCommunity)($member, $community);

        $this->assertDatabaseMissing('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_a_sub_admin_can_quit(): void
    {
        $community = Community::factory()->create();
        $sub = Member::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $sub->getKey()]);

        (new QuitCommunity)($sub, $community);

        $this->assertDatabaseMissing('community_members', ['member_id' => $sub->getKey()]);
    }

    public function test_the_admin_cannot_quit(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertFailsWith(CommunityActionFailure::AdminCannotQuit, fn () => (new QuitCommunity)($admin, $community));
        $this->assertDatabaseHas('community_members', ['member_id' => $admin->getKey()]);
    }

    public function test_a_non_member_cannot_quit(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new QuitCommunity)($stranger, $community));
    }
}
