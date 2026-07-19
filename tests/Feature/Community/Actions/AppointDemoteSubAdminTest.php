<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\AppointSubAdmin;
use App\Features\Community\Actions\DemoteSubAdmin;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\SubAdminAppointed;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Community\AssertsCommunityFailure;
use Tests\TestCase;

class AppointDemoteSubAdminTest extends TestCase
{
    use AssertsCommunityFailure;
    use RefreshDatabase;

    public function test_admin_appoints_a_plain_member_and_dispatches_the_event(): void
    {
        Event::fake();
        [$community, $admin, $member] = $this->communityWith('admin', 'member');

        (new AppointSubAdmin)($admin, $community, $member);

        $this->assertSame(CommunityRole::SubAdmin, $this->roleOf($community, $member));
        Event::assertDispatched(
            SubAdminAppointed::class,
            fn (SubAdminAppointed $e) => $e->community->is($community) && $e->appointer->is($admin) && $e->appointee->is($member),
        );
    }

    public function test_a_sub_admin_actor_cannot_appoint(): void
    {
        [$community, $sub, $member] = $this->communityWith('subAdmin', 'member');

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => (new AppointSubAdmin)($sub, $community, $member));
        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $member));
    }

    public function test_appointing_a_non_member_fails(): void
    {
        [$community, $admin] = $this->communityWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new AppointSubAdmin)($admin, $community, $stranger));
    }

    public function test_appointing_a_sub_admin_fails_as_not_plain(): void
    {
        [$community, $admin, $sub] = $this->communityWith('admin', 'subAdmin');

        $this->assertFailsWith(CommunityActionFailure::TargetNotPlainMember, fn () => (new AppointSubAdmin)($admin, $community, $sub));
    }

    public function test_appointing_the_pending_nominee_fails(): void
    {
        Event::fake();
        [$community, $admin, $nominee] = $this->communityWith('admin', 'member');
        $this->setPending($community, $nominee);

        $this->assertFailsWith(CommunityActionFailure::TargetIsPendingAdmin, fn () => (new AppointSubAdmin)($admin, $community, $nominee));
        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $nominee));
        Event::assertNotDispatched(SubAdminAppointed::class);
    }

    public function test_admin_demotes_a_sub_admin(): void
    {
        [$community, $admin, $sub] = $this->communityWith('admin', 'subAdmin');

        (new DemoteSubAdmin)($admin, $community, $sub);

        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $sub));
    }

    public function test_a_sub_admin_actor_cannot_demote(): void
    {
        [$community, $sub, $other] = $this->communityWith('subAdmin', 'subAdmin');

        $this->assertFailsWith(CommunityActionFailure::NotAdmin, fn () => (new DemoteSubAdmin)($sub, $community, $other));
    }

    public function test_demoting_a_non_member_fails(): void
    {
        [$community, $admin] = $this->communityWith('admin');
        $stranger = Member::factory()->create();

        $this->assertFailsWith(CommunityActionFailure::NotMember, fn () => (new DemoteSubAdmin)($admin, $community, $stranger));
    }

    public function test_demoting_a_plain_member_fails(): void
    {
        [$community, $admin, $member] = $this->communityWith('admin', 'member');

        $this->assertFailsWith(CommunityActionFailure::NotSubAdmin, fn () => (new DemoteSubAdmin)($admin, $community, $member));
    }

    public function test_demoting_a_sub_admin_who_is_the_nominee_keeps_the_pending_transfer(): void
    {
        [$community, $admin, $sub] = $this->communityWith('admin', 'subAdmin');
        $this->setPending($community, $sub);

        (new DemoteSubAdmin)($admin, $community, $sub);

        $this->assertSame(CommunityRole::Member, $this->roleOf($community, $sub));
        $this->assertSame($sub->getKey(), $community->fresh()->pending_admin_member_id);
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

    private function setPending(Community $community, Member $nominee): void
    {
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
    }
}
