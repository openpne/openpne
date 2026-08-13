<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Group\GroupRole;
use App\Features\Timeline\Queries\CommunityTimeline;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_this_communitys_top_level_posts(): void
    {
        $group = Group::factory()->create();
        $other = Group::factory()->create();
        $author = $this->joined($group);
        $this->join($other, $author);

        $mine = TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);
        $reply = TimelinePost::factory()->replyTo($mine)->create(['member_id' => $author->getKey()]);
        $elsewhere = TimelinePost::factory()->inGroup($other)->create(['member_id' => $author->getKey()]);
        $snsWide = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $ids = $this->ids($author, $group);

        $this->assertSame([$mine->getKey()], $ids);
        $this->assertNotContains($reply->getKey(), $ids);
        $this->assertNotContains($elsewhere->getKey(), $ids);
        $this->assertNotContains($snsWide->getKey(), $ids);
    }

    public function test_a_post_leaves_the_feed_when_its_author_leaves_the_community(): void
    {
        // OpenPNE 3's community feed required the author to still be a member, so an upgraded feed
        // shows no more than it did before. The permalink is deliberately not narrowed this way.
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        $author = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);

        $this->assertSame([$post->getKey()], $this->ids($viewer, $group));

        GroupMember::where('group_id', $group->getKey())
            ->where('member_id', $author->getKey())->delete();
        $this->assertSame([], $this->ids($viewer, $group));

        $this->join($group, $author);
        $this->assertSame([$post->getKey()], $this->ids($viewer, $group));
    }

    public function test_an_author_blocking_the_viewer_drops_out(): void
    {
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        $author = $this->joined($group);
        TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);

        DB::table('member_blocks')->insert([
            'blocker_id' => $author->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame([], $this->ids($viewer, $group));
    }

    public function test_the_visibility_ladder_does_not_narrow_a_community_feed(): void
    {
        // The community is the audience. A row stored at a stricter tier (an upgraded one, say)
        // still belongs to the members who may read the community.
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        $author = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->friends()->create(['member_id' => $author->getKey()]);

        $this->assertSame([$post->getKey()], $this->ids($viewer, $group));
    }

    /** @return list<int> */
    private function ids(Member $viewer, Group $group): array
    {
        return collect((new CommunityTimeline)($viewer, $group)->items())->map->getKey()->all();
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        $this->join($group, $member);

        return $member;
    }

    private function join(Group $group, Member $member): void
    {
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);
    }
}
