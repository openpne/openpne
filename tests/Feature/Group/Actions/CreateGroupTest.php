<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\CreateGroup;
use App\Features\Group\Data\GroupFormData;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupRole;
use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupCategory;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class CreateGroupTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_creates_community_with_creator_as_admin(): void
    {
        $creator = Member::factory()->create();
        $data = new GroupFormData('Hiking', 'desc', JoinPolicy::Approval, null, true, TopicReadAccess::MembersOnly, TopicPostAuthority::AdminsOnly);

        $group = app(CreateGroup::class)($creator, $data);

        $this->assertDatabaseHas('groups', ['id' => $group->getKey(), 'name' => 'Hiking']);
        $this->assertSame(JoinPolicy::Approval, $group->refresh()->register_policy);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $creator->getKey(),
            'role' => GroupRole::Admin->value,
        ]);
    }

    public function test_rejects_a_category_members_cannot_use(): void
    {
        $creator = Member::factory()->create();
        $category = GroupCategory::factory()->adminOnly()->create();
        $data = new GroupFormData('X', null, JoinPolicy::Open, $category->getKey(), true, TopicReadAccess::Everyone, TopicPostAuthority::Members);

        $this->assertFailsWith(GroupActionFailure::CategoryNotAllowed, fn () => app(CreateGroup::class)($creator, $data));
        $this->assertDatabaseMissing('groups', ['name' => 'X']);
    }

    public function test_allows_a_member_creatable_category(): void
    {
        $creator = Member::factory()->create();
        $category = GroupCategory::factory()->create();
        $data = new GroupFormData('Y', null, JoinPolicy::Open, $category->getKey(), true, TopicReadAccess::Everyone, TopicPostAuthority::Members);

        $group = app(CreateGroup::class)($creator, $data);

        $this->assertSame($category->getKey(), $group->group_category_id);
    }
}
