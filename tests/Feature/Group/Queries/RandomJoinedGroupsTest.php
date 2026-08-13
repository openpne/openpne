<?php

namespace Tests\Feature\Group\Queries;

use App\Features\Group\Queries\RandomJoinedGroups;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RandomJoinedGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Group $group): void
    {
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_returns_only_communities_the_viewer_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Group::factory()->create();
        Group::factory()->create(); // not joined
        $this->join($viewer, $joined);

        $result = (new RandomJoinedGroups)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($joined->getKey(), $result->first()->getKey());
    }

    public function test_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        foreach (Group::factory()->count(12)->create() as $group) {
            $this->join($viewer, $group);
        }

        $this->assertCount(9, (new RandomJoinedGroups)($viewer));
        $this->assertCount(3, (new RandomJoinedGroups)($viewer, 3));
    }

    public function test_is_empty_without_memberships(): void
    {
        $this->assertCount(0, (new RandomJoinedGroups)(Member::factory()->create()));
    }
}
