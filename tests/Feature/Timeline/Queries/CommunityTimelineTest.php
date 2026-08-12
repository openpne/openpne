<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Community\CommunityRole;
use App\Features\Timeline\Queries\CommunityTimeline;
use App\Models\Community;
use App\Models\CommunityMember;
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
        $community = Community::factory()->create();
        $other = Community::factory()->create();
        $author = $this->joined($community);
        $this->join($other, $author);

        $mine = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);
        $reply = TimelinePost::factory()->replyTo($mine)->create(['member_id' => $author->getKey()]);
        $elsewhere = TimelinePost::factory()->inCommunity($other)->create(['member_id' => $author->getKey()]);
        $snsWide = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $ids = $this->ids($author, $community);

        $this->assertSame([$mine->getKey()], $ids);
        $this->assertNotContains($reply->getKey(), $ids);
        $this->assertNotContains($elsewhere->getKey(), $ids);
        $this->assertNotContains($snsWide->getKey(), $ids);
    }

    public function test_a_post_leaves_the_feed_when_its_author_leaves_the_community(): void
    {
        // OpenPNE 3's community feed required the author to still be a member, so an upgraded feed
        // shows no more than it did before. The permalink is deliberately not narrowed this way.
        $community = Community::factory()->create();
        $viewer = $this->joined($community);
        $author = $this->joined($community);
        $post = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);

        $this->assertSame([$post->getKey()], $this->ids($viewer, $community));

        CommunityMember::where('community_id', $community->getKey())
            ->where('member_id', $author->getKey())->delete();
        $this->assertSame([], $this->ids($viewer, $community));

        $this->join($community, $author);
        $this->assertSame([$post->getKey()], $this->ids($viewer, $community));
    }

    public function test_an_author_blocking_the_viewer_drops_out(): void
    {
        $community = Community::factory()->create();
        $viewer = $this->joined($community);
        $author = $this->joined($community);
        TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);

        DB::table('member_blocks')->insert([
            'blocker_id' => $author->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame([], $this->ids($viewer, $community));
    }

    public function test_the_visibility_ladder_does_not_narrow_a_community_feed(): void
    {
        // The community is the audience. A row stored at a stricter tier (an upgraded one, say)
        // still belongs to the members who may read the community.
        $community = Community::factory()->create();
        $viewer = $this->joined($community);
        $author = $this->joined($community);
        $post = TimelinePost::factory()->inCommunity($community)->friends()->create(['member_id' => $author->getKey()]);

        $this->assertSame([$post->getKey()], $this->ids($viewer, $community));
    }

    /** @return list<int> */
    private function ids(Member $viewer, Community $community): array
    {
        return collect((new CommunityTimeline)($viewer, $community)->items())->map->getKey()->all();
    }

    private function joined(Community $community): Member
    {
        $member = Member::factory()->create();
        $this->join($community, $member);

        return $member;
    }

    private function join(Community $community, Member $member): void
    {
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => CommunityRole::Member,
        ]);
    }
}
