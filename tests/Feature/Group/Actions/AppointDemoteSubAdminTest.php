<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\AppointSubAdmin;
use App\Features\Group\Actions\DemoteSubAdmin;
use App\Features\Group\GroupRole;
use App\Features\Group\Events\SubAdminAppointed;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class AppointDemoteSubAdminTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_admin_appoints_a_plain_member_and_dispatches_the_event(): void
    {
        Event::fake();
        [$group, $admin, $member] = $this->groupWith('admin', 'member');

        (new AppointSubAdmin)($admin, $group, $member);

        $this->assertSame(GroupRole::SubAdmin, $this->roleOf($group, $member));
        Event::assertDispatched(
            SubAdminAppointed::class,
            fn (SubAdminAppointed $e) => $e->group->is($group) && $e->appointer->is($admin) && $e->appointee->is($member),
        );
    }

    public function test_a_sub_admin_actor_cannot_appoint(): void
    {
        [$group, $sub, $member] = $this->groupWith('subAdmin', 'member');

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => (new AppointSubAdmin)($sub, $group, $member));
        $this->assertSame(GroupRole::Member, $this->roleOf($group, $member));
    }

    public function test_appointing_a_non_member_fails(): void
    {
        [$group, $admin] = $this->groupWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new AppointSubAdmin)($admin, $group, $stranger));
    }

    public function test_appointing_a_sub_admin_fails_as_not_plain(): void
    {
        [$group, $admin, $sub] = $this->groupWith('admin', 'subAdmin');

        $this->assertFailsWith(GroupActionFailure::TargetNotPlainMember, fn () => (new AppointSubAdmin)($admin, $group, $sub));
    }

    public function test_appointing_the_pending_nominee_fails(): void
    {
        Event::fake();
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);

        $this->assertFailsWith(GroupActionFailure::TargetIsPendingAdmin, fn () => (new AppointSubAdmin)($admin, $group, $nominee));
        $this->assertSame(GroupRole::Member, $this->roleOf($group, $nominee));
        Event::assertNotDispatched(SubAdminAppointed::class);
    }

    public function test_admin_demotes_a_sub_admin(): void
    {
        [$group, $admin, $sub] = $this->groupWith('admin', 'subAdmin');

        (new DemoteSubAdmin)($admin, $group, $sub);

        $this->assertSame(GroupRole::Member, $this->roleOf($group, $sub));
    }

    public function test_a_sub_admin_actor_cannot_demote(): void
    {
        [$group, $sub, $other] = $this->groupWith('subAdmin', 'subAdmin');

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => (new DemoteSubAdmin)($sub, $group, $other));
    }

    public function test_demoting_a_non_member_fails(): void
    {
        [$group, $admin] = $this->groupWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new DemoteSubAdmin)($admin, $group, $stranger));
    }

    public function test_demoting_a_plain_member_fails(): void
    {
        [$group, $admin, $member] = $this->groupWith('admin', 'member');

        $this->assertFailsWith(GroupActionFailure::NotSubAdmin, fn () => (new DemoteSubAdmin)($admin, $group, $member));
    }

    public function test_demoting_a_sub_admin_who_is_the_nominee_keeps_the_pending_transfer(): void
    {
        [$group, $admin, $sub] = $this->groupWith('admin', 'subAdmin');
        $this->setPending($group, $sub);

        (new DemoteSubAdmin)($admin, $group, $sub);

        $this->assertSame(GroupRole::Member, $this->roleOf($group, $sub));
        $this->assertSame($sub->getKey(), $group->fresh()->pending_admin_member_id);
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

    private function roleOf(Group $group, Member $member): ?GroupRole
    {
        return GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->first()?->role;
    }

    private function setPending(Group $group, Member $nominee): void
    {
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
    }
}
