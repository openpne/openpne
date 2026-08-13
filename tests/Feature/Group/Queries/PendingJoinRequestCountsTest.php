<?php

namespace Tests\Feature\Group\Queries;

use App\Features\Group\Queries\PendingJoinRequestCounts;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingJoinRequestCountsTest extends TestCase
{
    use RefreshDatabase;

    private function groupWith(Member $viewer, string $role): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->{$role}()->create([
            'group_id' => $group->getKey(),
            'member_id' => $viewer->getKey(),
        ]);

        return $group;
    }

    private function addApplicant(Group $group, int $n = 1): void
    {
        $group->applicants()->attach(Member::factory()->count($n)->create()->pluck('id'));
    }

    public function test_returns_admin_communities_with_pending_applicants_and_the_count(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->groupWith($viewer, 'admin');
        $this->addApplicant($group, 2);

        $result = (new PendingJoinRequestCounts)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($group->getKey(), $result->first()->getKey());
        $this->assertSame(2, (int) $result->first()->applicants_count);
    }

    public function test_excludes_communities_the_viewer_only_sub_admins_or_belongs_to(): void
    {
        $viewer = Member::factory()->create();
        // The approval page's Gate is Admin-only, so a SubAdmin/Member notice would 404 — exclude them.
        $this->addApplicant($this->groupWith($viewer, 'subAdmin'));
        $this->addApplicant($this->groupWith($viewer, 'member'));

        $this->assertCount(0, (new PendingJoinRequestCounts)($viewer));
    }

    public function test_excludes_admin_communities_with_no_pending_applicants(): void
    {
        $viewer = Member::factory()->create();
        $this->groupWith($viewer, 'admin'); // no applicants

        $this->assertCount(0, (new PendingJoinRequestCounts)($viewer));
    }
}
