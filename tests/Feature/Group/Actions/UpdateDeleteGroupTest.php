<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\Actions\UpdateGroup;
use App\Features\Group\Data\GroupFormData;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class UpdateDeleteGroupTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    private function data(?int $categoryId = null): GroupFormData
    {
        return new GroupFormData('Renamed', 'new desc', JoinPolicy::Approval, $categoryId, true, TopicReadAccess::Everyone, TopicPostAuthority::Members);
    }

    public function test_admin_can_update(): void
    {
        $group = Group::factory()->create(['name' => 'Old']);
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        app(UpdateGroup::class)($admin, $group, $this->data());

        $this->assertSame('Renamed', $group->refresh()->name);
        $this->assertSame(JoinPolicy::Approval, $group->register_policy);
    }

    public function test_sub_admin_can_update(): void
    {
        $group = Group::factory()->create();
        $sub = Member::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);

        app(UpdateGroup::class)($sub, $group, $this->data());

        $this->assertSame('Renamed', $group->refresh()->name);
    }

    public function test_a_plain_member_cannot_update(): void
    {
        $group = Group::factory()->create(['name' => 'Old']);
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->assertFailsWith(GroupActionFailure::NotManager, fn () => app(UpdateGroup::class)($member, $group, $this->data()));
        $this->assertSame('Old', $group->refresh()->name);
    }

    public function test_update_rejects_a_category_members_cannot_use(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $category = GroupCategory::factory()->adminOnly()->create();

        $this->assertFailsWith(GroupActionFailure::CategoryNotAllowed, fn () => app(UpdateGroup::class)($admin, $group, $this->data($category->getKey())));
    }

    public function test_update_keeps_an_admin_only_category_already_set(): void
    {
        // A community sitting in an admin-only category may stay there: only switching to a
        // non-member-creatable category is refused (OpenPNE 3 checkCreatable).
        $category = GroupCategory::factory()->adminOnly()->create();
        $group = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        app(UpdateGroup::class)($admin, $group, $this->data($category->getKey()));

        $this->assertDatabaseHas('groups', [
            'id' => $group->getKey(),
            'group_category_id' => $category->getKey(),
            'name' => 'Renamed',
        ]);
    }

    public function test_admin_can_delete_and_memberships_cascade(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        app(DeleteGroup::class)($admin, $group);

        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->getKey()]);
    }

    public function test_a_non_admin_cannot_delete(): void
    {
        $group = Group::factory()->create();
        $sub = Member::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => app(DeleteGroup::class)($sub, $group));
        $this->assertDatabaseHas('groups', ['id' => $group->getKey()]);
    }
}
