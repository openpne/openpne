<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\AcceptAdminTransfer;
use App\Features\Group\Actions\DropMember;
use App\Features\Group\Actions\QuitGroup;
use App\Features\Group\Actions\RejectAdminTransfer;
use App\Features\Group\Actions\RequestAdminTransfer;
use App\Features\Group\GroupRole;
use App\Features\Group\Events\AdminTransferRequested;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Group\AssertsGroupFailure;
use Tests\TestCase;

class AdminTransferTest extends TestCase
{
    use AssertsGroupFailure;
    use RefreshDatabase;

    public function test_admin_requests_a_transfer_and_dispatches_the_event(): void
    {
        Event::fake();
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');

        (new RequestAdminTransfer)($admin, $group, $nominee);

        $this->assertSame($nominee->getKey(), $group->fresh()->pending_admin_member_id);
        Event::assertDispatched(
            AdminTransferRequested::class,
            fn (AdminTransferRequested $e) => $e->group->is($group) && $e->requester->is($admin) && $e->nominee->is($nominee),
        );
    }

    public function test_a_sub_admin_may_be_nominated(): void
    {
        [$group, $admin, $sub] = $this->groupWith('admin', 'subAdmin');

        (new RequestAdminTransfer)($admin, $group, $sub);

        $this->assertSame($sub->getKey(), $group->fresh()->pending_admin_member_id);
    }

    public function test_a_new_request_replaces_the_previous_nominee(): void
    {
        [$group, $admin, $first, $second] = $this->groupWith('admin', 'member', 'member');
        (new RequestAdminTransfer)($admin, $group, $first);

        (new RequestAdminTransfer)($admin, $group, $second);

        $this->assertSame($second->getKey(), $group->fresh()->pending_admin_member_id);
    }

    public function test_re_requesting_the_same_nominee_fails_and_dispatches_no_second_event(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        (new RequestAdminTransfer)($admin, $group, $nominee);

        Event::fake();
        $this->assertFailsWith(
            GroupActionFailure::TransferAlreadyRequested,
            fn () => (new RequestAdminTransfer)($admin, $group, $nominee),
        );
        Event::assertNotDispatched(AdminTransferRequested::class);
    }

    public function test_a_non_admin_cannot_request(): void
    {
        [$group, $sub, $nominee] = $this->groupWith('subAdmin', 'member');

        $this->assertFailsWith(GroupActionFailure::NotAdmin, fn () => (new RequestAdminTransfer)($sub, $group, $nominee));
        $this->assertNull($group->fresh()->pending_admin_member_id);
    }

    public function test_nominating_a_non_member_fails(): void
    {
        [$group, $admin] = $this->groupWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new RequestAdminTransfer)($admin, $group, $stranger));
    }

    public function test_nominating_the_admin_themselves_fails(): void
    {
        [$group, $admin] = $this->groupWith('admin');

        $this->assertFailsWith(GroupActionFailure::TargetNotPlainMember, fn () => (new RequestAdminTransfer)($admin, $group, $admin));
    }

    public function test_accepting_promotes_a_plain_member_and_demotes_the_old_admin(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);

        (new AcceptAdminTransfer)($nominee, $group);

        $this->assertSame(GroupRole::Admin, $this->roleOf($group, $nominee));
        $this->assertSame(GroupRole::Member, $this->roleOf($group, $admin));
        $this->assertNull($group->fresh()->pending_admin_member_id);
        $this->assertSame(1, $this->adminCount($group));
    }

    public function test_accepting_promotes_a_sub_admin_nominee(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'subAdmin');
        $this->setPending($group, $nominee);

        (new AcceptAdminTransfer)($nominee, $group);

        $this->assertSame(GroupRole::Admin, $this->roleOf($group, $nominee));
        $this->assertSame(GroupRole::Member, $this->roleOf($group, $admin));
        $this->assertSame(1, $this->adminCount($group));
    }

    public function test_accepting_when_not_the_nominee_fails(): void
    {
        [$group, $admin, $nominee, $other] = $this->groupWith('admin', 'member', 'member');
        $this->setPending($group, $nominee);

        $this->assertFailsWith(GroupActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($other, $group));
        $this->assertSame(GroupRole::Admin, $this->roleOf($group, $admin));
    }

    public function test_accepting_when_nothing_is_pending_fails(): void
    {
        [$group, $admin, $member] = $this->groupWith('admin', 'member');

        $this->assertFailsWith(GroupActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($member, $group));
    }

    public function test_accepting_after_the_nominee_quit_fails(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);
        (new QuitGroup)($nominee, $group); // clears pending

        $this->assertFailsWith(GroupActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($nominee, $group));
    }

    public function test_accepting_after_the_nominee_was_dropped_fails(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);
        (new DropMember)($admin, $group, $nominee); // clears pending

        $this->assertFailsWith(GroupActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($nominee, $group));
    }

    public function test_defensive_accept_clears_a_dangling_pending_when_the_nominee_left_without_clearing_it(): void
    {
        // A pending seat still pointing at a member whose membership vanished by another path (e.g. an
        // FK cascade in a withdrawal race) is cleared and refused, not promoted.
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);
        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('member_id', $nominee->getKey())
            ->delete();

        $this->assertFailsWith(GroupActionFailure::NotMember, fn () => (new AcceptAdminTransfer)($nominee, $group));
        $this->assertNull($group->fresh()->pending_admin_member_id);
        $this->assertSame(GroupRole::Admin, $this->roleOf($group, $admin));
    }

    public function test_rejecting_clears_the_pending_transfer(): void
    {
        [$group, $admin, $nominee] = $this->groupWith('admin', 'member');
        $this->setPending($group, $nominee);

        (new RejectAdminTransfer)($nominee, $group);

        $this->assertNull($group->fresh()->pending_admin_member_id);
    }

    public function test_rejecting_when_not_the_nominee_fails(): void
    {
        [$group, $admin, $nominee, $other] = $this->groupWith('admin', 'member', 'member');
        $this->setPending($group, $nominee);

        $this->assertFailsWith(GroupActionFailure::NoTransferPending, fn () => (new RejectAdminTransfer)($other, $group));
        $this->assertSame($nominee->getKey(), $group->fresh()->pending_admin_member_id);
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

    private function adminCount(Group $group): int
    {
        return GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('role', GroupRole::Admin->value)
            ->count();
    }

    private function setPending(Group $group, Member $nominee): void
    {
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
    }
}
