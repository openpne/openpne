<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\CreateCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\Community\JoinPolicy;
use App\Models\CommunityCategory;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class CreateCommunityTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_creates_community_with_creator_as_admin(): void
    {
        $creator = Member::factory()->create();
        $data = new CommunityFormData('Hiking', 'desc', JoinPolicy::Approval, null);

        $community = app(CreateCommunity::class)($creator, $data);

        $this->assertDatabaseHas('communities', ['id' => $community->getKey(), 'name' => 'Hiking']);
        $this->assertSame(JoinPolicy::Approval, $community->refresh()->register_policy);
        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->getKey(),
            'member_id' => $creator->getKey(),
            'role' => CommunityRole::Admin->value,
        ]);
    }

    public function test_rejects_a_category_members_cannot_use(): void
    {
        $creator = Member::factory()->create();
        $category = CommunityCategory::factory()->adminOnly()->create();
        $data = new CommunityFormData('X', null, JoinPolicy::Open, $category->getKey());

        $this->assertFailsWith(CommunityActionFailure::CategoryNotAllowed, fn () => app(CreateCommunity::class)($creator, $data));
        $this->assertDatabaseMissing('communities', ['name' => 'X']);
    }

    public function test_allows_a_member_creatable_category(): void
    {
        $creator = Member::factory()->create();
        $category = CommunityCategory::factory()->create();
        $data = new CommunityFormData('Y', null, JoinPolicy::Open, $category->getKey());

        $community = app(CreateCommunity::class)($creator, $data);

        $this->assertSame($category->getKey(), $community->community_category_id);
    }
}
