<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\DropMember;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class DropMemberTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_an_admin_drops_a_plain_member(): void
    {
        [$group, $admin, $member] = $this->groupWith('admin', 'member');

        (new DropMember)($admin, $group, $member);

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_a_sub_admin_drops_a_plain_member(): void
    {
        [$group, $sub, $member] = $this->groupWith('subAdmin', 'member');

        (new DropMember)($sub, $group, $member);

        $this->assertDatabaseMissing('group_members', ['member_id' => $member->getKey()]);
    }

    public function test_a_plain_member_cannot_drop(): void
    {
        [$group, $actor, $member] = $this->groupWith('member', 'member');

        $this->assertFailsWith(GroupActionFailure::NotManager, fn () => (new DropMember)($actor, $group, $member));
        $this->assertDatabaseHas('group_members', ['member_id' => $member->getKey()]);
    }

    public function test_dropping_a_non_member_fails(): void
    {
        [$group, $admin] = $this->groupWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new DropMember)($admin, $group, $stranger));
    }

    public function test_dropping_an_admin_or_sub_admin_fails_as_not_plain(): void
    {
        [$group, $admin, $sub] = $this->groupWith('admin', 'subAdmin');

        $this->assertFailsWith(GroupActionFailure::TargetNotPlainMember, fn () => (new DropMember)($admin, $group, $sub));
    }

    public function test_an_admin_cannot_drop_themselves(): void
    {
        [$group, $admin] = $this->groupWith('admin');

        $this->assertFailsWith(GroupActionFailure::TargetNotPlainMember, fn () => (new DropMember)($admin, $group, $admin));
        $this->assertDatabaseHas('group_members', ['member_id' => $admin->getKey()]);
    }

    public function test_dropping_the_pending_nominee_clears_the_transfer(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        (new DropMember)($admin, $group, $nominee);

        $this->assertDatabaseMissing('group_members', ['member_id' => $nominee->getKey()]);
        $this->assertNull($group->fresh()->pending_admin_member_id);
    }

    /**
     * @return array{0: Group, ...}
     */
    private function groupWith(string ...$roles): array
    {
        $group = Group::factory()->create();
        $out = [$group];

        foreach ($roles as $role) {
            $member = Member::factory()->create();
            GroupMember::factory()->{$role}()->create([
                'group_id' => $group->getKey(),
                'member_id' => $member->getKey(),
            ]);
            $out[] = $member;
        }

        return $out;
    }
}
