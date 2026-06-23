<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\Actions\UpdateCommunity;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class UpdateDeleteCommunityTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    private function data(?int $categoryId = null): CommunityFormData
    {
        return new CommunityFormData('Renamed', 'new desc', JoinPolicy::Approval, $categoryId);
    }

    public function test_admin_can_update(): void
    {
        $community = Community::factory()->create(['name' => 'Old']);
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        app(UpdateCommunity::class)($admin, $community, $this->data());

        $this->assertSame('Renamed', $community->refresh()->name);
        $this->assertSame(JoinPolicy::Approval, $community->register_policy);
    }

    public function test_sub_admin_can_update(): void
    {
        $community = Community::factory()->create();
        $sub = Member::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $sub->getKey()]);

        app(UpdateCommunity::class)($sub, $community, $this->data());

        $this->assertSame('Renamed', $community->refresh()->name);
    }

    public function test_a_plain_member_cannot_update(): void
    {
        $community = Community::factory()->create(['name' => 'Old']);
        $member = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(CommunityActionFailure::NotManager, fn () => app(UpdateCommunity::class)($member, $community, $this->data()));
        $this->assertSame('Old', $community->refresh()->name);
    }

    public function test_update_rejects_a_category_members_cannot_use(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);
        $category = CommunityCategory::factory()->adminOnly()->create();

        $this->assertFailsWith(CommunityActionFailure::CategoryNotAllowed, fn () => app(UpdateCommunity::class)($admin, $community, $this->data($category->getKey())));
    }

    public function test_update_keeps_an_admin_only_category_already_set(): void
    {
        // A community sitting in an admin-only category may stay there: only switching to a
        // non-member-creatable category is refused (OpenPNE 3 checkCreatable).
        $category = CommunityCategory::factory()->adminOnly()->create();
        $community = Community::factory()->create(['community_category_id' => $category->getKey()]);
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        app(UpdateCommunity::class)($admin, $community, $this->data($category->getKey()));

        $this->assertDatabaseHas('communities', [
            'id' => $community->getKey(),
            'community_category_id' => $category->getKey(),
            'name' => 'Renamed',
        ]);
    }

    public function test_admin_can_delete_and_memberships_cascade(): void
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        app(DeleteCommunity::class)($admin, $community);

        $this->assertDatabaseMissing('communities', ['id' => $community->getKey()]);
        $this->assertDatabaseMissing('community_members', ['community_id' => $community->getKey()]);
    }

    public function test_a_non_admin_cannot_delete(): void
    {
        $community = Community::factory()->create();
        $sub = Member::factory()->create();
        CommunityMember::factory()->subAdmin()->create(['community_id' => $community->getKey(), 'member_id' => $sub->getKey()]);

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => app(DeleteCommunity::class)($sub, $community));
        $this->assertDatabaseHas('communities', ['id' => $community->getKey()]);
    }
}
