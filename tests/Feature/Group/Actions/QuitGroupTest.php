<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\QuitGroup;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class QuitGroupTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_a_member_can_quit(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        (new QuitGroup)($member, $group);

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_a_sub_admin_can_quit(): void
    {
        $group = Group::factory()->create();
        $sub = Member::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $sub->getKey()]);

        (new QuitGroup)($sub, $group);

        $this->assertDatabaseMissing('group_members', ['member_id' => $sub->getKey()]);
    }

    public function test_the_admin_cannot_quit(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertFailsWith(GroupActionFailure::AdminCannotQuit, fn () => (new QuitGroup)($admin, $group));
        $this->assertDatabaseHas('group_members', ['member_id' => $admin->getKey()]);
    }

    public function test_a_non_member_cannot_quit(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new QuitGroup)($stranger, $group));
    }

    public function test_the_pending_nominee_quitting_clears_the_transfer(): void
    {
        $group = Group::factory()->create();
        $nominee = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $nominee->getKey()]);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        (new QuitGroup)($nominee, $group);

        $this->assertNull($group->fresh()->pending_admin_member_id);
    }

    public function test_someone_elses_quit_leaves_the_pending_transfer_intact(): void
    {
        $group = Group::factory()->create();
        $nominee = Member::factory()->create();
        $other = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $nominee->getKey()]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $other->getKey()]);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        (new QuitGroup)($other, $group);

        $this->assertSame($nominee->getKey(), $group->fresh()->pending_admin_member_id);
    }
}
