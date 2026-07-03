<?php

namespace Tests\Feature\Community\Queries;

use App\Features\Community\Queries\PendingJoinRequestCounts;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingJoinRequestCountsTest extends TestCase
{
    use RefreshDatabase;

    private function communityWith(Member $viewer, string $role): Community
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->{$role}()->create([
            'community_id' => $community->getKey(),
            'member_id' => $viewer->getKey(),
        ]);

        return $community;
    }

    private function addApplicant(Community $community, int $n = 1): void
    {
        $community->applicants()->attach(Member::factory()->count($n)->create()->pluck('id'));
    }

    public function test_returns_admin_communities_with_pending_applicants_and_the_count(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->communityWith($viewer, 'admin');
        $this->addApplicant($community, 2);

        $result = (new PendingJoinRequestCounts)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($community->getKey(), $result->first()->getKey());
        $this->assertSame(2, (int) $result->first()->applicants_count);
    }

    public function test_excludes_communities_the_viewer_only_sub_admins_or_belongs_to(): void
    {
        $viewer = Member::factory()->create();
        // The approval page's Gate is Admin-only, so a SubAdmin/Member notice would 404 — exclude them.
        $this->addApplicant($this->communityWith($viewer, 'subAdmin'));
        $this->addApplicant($this->communityWith($viewer, 'member'));

        $this->assertCount(0, (new PendingJoinRequestCounts)($viewer));
    }

    public function test_excludes_admin_communities_with_no_pending_applicants(): void
    {
        $viewer = Member::factory()->create();
        $this->communityWith($viewer, 'admin'); // no applicants

        $this->assertCount(0, (new PendingJoinRequestCounts)($viewer));
    }
}
