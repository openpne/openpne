<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\AcceptAdminTransfer;
use App\Features\Community\Actions\DropMember;
use App\Features\Community\Actions\QuitCommunity;
use App\Features\Community\Actions\RejectAdminTransfer;
use App\Features\Community\Actions\RequestAdminTransfer;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\AdminTransferRequested;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class AdminTransferTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_admin_requests_a_transfer_and_dispatches_the_event(): void
    {
        Event::fake();
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');

        (new RequestAdminTransfer)($admin, $community, $nominee);

        $this->assertSame($nominee->getKey(), $community->fresh()->pending_admin_member_id);
        Event::assertDispatched(
            AdminTransferRequested::class,
            fn (AdminTransferRequested $e) => $e->community->is($community) && $e->requester->is($admin) && $e->nominee->is($nominee),
        );
    }

    public function test_a_sub_admin_may_be_nominated(): void
    {
        [$community, $admin, $sub] = $this->communityWith('admin', 'subAdmin');

        (new RequestAdminTransfer)($admin, $community, $sub);

        $this->assertSame($sub->getKey(), $community->fresh()->pending_admin_member_id);
    }

    public function test_a_new_request_replaces_the_previous_nominee(): void
    {
        [$community, $admin, $first, $second] = $this->communityWith('admin', 'member', 'member');
        (new RequestAdminTransfer)($admin, $community, $first);

        (new RequestAdminTransfer)($admin, $community, $second);

        $this->assertSame($second->getKey(), $community->fresh()->pending_admin_member_id);
    }

    public function test_re_requesting_the_same_nominee_fails_and_dispatches_no_second_event(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        (new RequestAdminTransfer)($admin, $community, $nominee);

        Event::fake();
        $this->assertFailsWith(
            CommunityActionFailure::TransferAlreadyRequested,
            fn () => (new RequestAdminTransfer)($admin, $community, $nominee),
        );
        Event::assertNotDispatched(AdminTransferRequested::class);
    }

    public function test_a_non_admin_cannot_request(): void
    {
        [$community, $sub, $nominee] = $this->communityWith('subAdmin', 'member');

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => (new RequestAdminTransfer)($sub, $community, $nominee));
        $this->assertNull($community->fresh()->pending_admin_member_id);
    }

    public function test_nominating_a_non_member_fails(): void
    {
        [$community, $admin] = $this->communityWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new RequestAdminTransfer)($admin, $community, $stranger));
    }

    public function test_nominating_the_admin_themselves_fails(): void
    {
        [$community, $admin] = $this->communityWith('admin');

        $this->assertFailsWith(CommunityActionFailure::TargetNotPlainMember, fn () => (new RequestAdminTransfer)($admin, $community, $admin));
    }

    public function test_accepting_promotes_a_plain_member_and_demotes_the_old_admin(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);

        (new AcceptAdminTransfer)($nominee, $community);

        $this->assertSame(CommunityRole::Admin, $this->roleOf($community, $nominee));
        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $admin));
        $this->assertNull($community->fresh()->pending_admin_member_id);
        $this->assertSame(1, $this->adminCount($community));
    }

    public function test_accepting_promotes_a_sub_admin_nominee(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'subAdmin');
        $this->setPending($community, $nominee);

        (new AcceptAdminTransfer)($nominee, $community);

        $this->assertSame(CommunityRole::Admin, $this->roleOf($community, $nominee));
        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $admin));
        $this->assertSame(1, $this->adminCount($community));
    }

    public function test_accepting_when_not_the_nominee_fails(): void
    {
        [$community, $admin, $nominee, $other] = $this->communityWith('admin', 'member', 'member');
        $this->setPending($community, $nominee);

        $this->assertFailsWith(CommunityActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($other, $community));
        $this->assertSame(CommunityRole::Admin, $this->roleOf($community, $admin));
    }

    public function test_accepting_when_nothing_is_pending_fails(): void
    {
        [$community, $admin, $member] = $this->communityWith('admin', 'member');

        $this->assertFailsWith(CommunityActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($member, $community));
    }

    public function test_accepting_after_the_nominee_quit_fails(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);
        (new QuitCommunity)($nominee, $community); // clears pending

        $this->assertFailsWith(CommunityActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($nominee, $community));
    }

    public function test_accepting_after_the_nominee_was_dropped_fails(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);
        (new DropMember)($admin, $community, $nominee); // clears pending

        $this->assertFailsWith(CommunityActionFailure::NoTransferPending, fn () => (new AcceptAdminTransfer)($nominee, $community));
    }

    public function test_defensive_accept_clears_a_dangling_pending_when_the_nominee_left_without_clearing_it(): void
    {
        // A pending seat still pointing at a member whose membership vanished by another path (e.g. an
        // FK cascade in a withdrawal race) is cleared and refused, not promoted.
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);
        CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('member_id', $nominee->getKey())
            ->delete();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new AcceptAdminTransfer)($nominee, $community));
        $this->assertNull($community->fresh()->pending_admin_member_id);
        $this->assertSame(CommunityRole::Admin, $this->roleOf($community, $admin));
    }

    public function test_rejecting_clears_the_pending_transfer(): void
    {
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);

        (new RejectAdminTransfer)($nominee, $community);

        $this->assertNull($community->fresh()->pending_admin_member_id);
    }

    public function test_rejecting_when_not_the_nominee_fails(): void
    {
        [$community, $admin, $nominee, $other] = $this->communityWith('admin', 'member', 'member');
        $this->setPending($community, $nominee);

        $this->assertFailsWith(CommunityActionFailure::NoTransferPending, fn () => (new RejectAdminTransfer)($other, $community));
        $this->assertSame($nominee->getKey(), $community->fresh()->pending_admin_member_id);
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

    private function roleOf(Community $community, Member $member): ?CommunityRole
    {
        return CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('member_id', $member->getKey())
            ->first()?->role;
    }

    private function adminCount(Community $community): int
    {
        return CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('role', CommunityRole::Admin->value)
            ->count();
    }

    private function setPending(Community $community, Member $nominee): void
    {
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
    }
}
