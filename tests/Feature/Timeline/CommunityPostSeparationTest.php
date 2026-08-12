<?php

namespace Tests\Feature\Timeline;

use App\Features\Community\CommunityRole;
use App\Features\Profile\Queries\ProfileStats;
use App\Features\Timeline\Queries\AllMemberFeed;
use App\Features\Timeline\Queries\FriendFeed;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\TagFeed;
use App\Models\Community;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A community-scoped post belongs to its community's timeline and to nothing else. Every SNS-wide
 * read is pinned here directly rather than through a page, because a page proves only the one route
 * it exercises: the dashboard digest and the Classic timeline gadgets are consumers of these same
 * queries, so a query that stays clean keeps them clean.
 *
 * The author is deliberately the viewer, a friend, and a community co-member all at once — every
 * audience branch that could admit the post is open, so the only thing keeping it out is the
 * community exclusion. Remove `whereNull('community_id')` from either scope and these go red.
 */
class CommunityPostSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_community_post_is_absent_from_every_sns_wide_feed(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $author);

        $community = Community::factory()->create();
        $this->join($community, $viewer);
        $this->join($community, $author);

        $communityPost = TimelinePost::factory()->inCommunity($community)->create([
            'member_id' => $author->getKey(),
            'body' => 'community only #tag',
        ]);
        $communityPost->tags()->create(['tag' => 'tag', 'offset' => 15, 'length' => 4]);

        $snsPost = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'body' => 'sns wide #tag',
        ]);
        $snsPost->tags()->create(['tag' => 'tag', 'offset' => 9, 'length' => 4]);

        $this->assertFeedExcludes($communityPost, $snsPost, (new HomeFeed)($viewer)->items());
        $this->assertFeedExcludes($communityPost, $snsPost, (new AllMemberFeed)($viewer)->items());
        $this->assertFeedExcludes($communityPost, $snsPost, (new FriendFeed)($viewer)->items());
        $this->assertFeedExcludes($communityPost, $snsPost, (new TagFeed)($viewer, 'tag')->items());
        $this->assertFeedExcludes($communityPost, $snsPost, (new MemberTimeline)($viewer, $author)->items());
    }

    public function test_the_author_does_not_see_their_own_community_post_in_their_timeline(): void
    {
        $author = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($community, $author);

        $communityPost = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);

        // Own posts are the widest branch in both scopes (every visibility, no clearance check), so
        // this is where an exclusion applied to the audience instead of the query would leak.
        $this->assertNotContains(
            $communityPost->getKey(),
            collect((new MemberTimeline)($author, $author)->items())->map->getKey()->all(),
        );
        $this->assertNotContains(
            $communityPost->getKey(),
            collect((new HomeFeed)($author)->items())->map->getKey()->all(),
        );
    }

    public function test_profile_activity_count_ignores_community_posts(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create();
        $this->join($community, $author);

        TimelinePost::factory()->inCommunity($community)->count(3)->create(['member_id' => $author->getKey()]);
        TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $this->assertSame(1, (new ProfileStats)($viewer, $author)['activity']);
    }

    public function test_a_reply_inherits_its_parents_community(): void
    {
        $author = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($community, $author);

        $parent = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $author->getKey()]);
        $reply = TimelinePost::factory()->replyTo($parent)->create(['member_id' => $author->getKey()]);

        // Only the inheritance is asserted. A reply is already absent from the SNS-wide feeds by
        // their in_reply_to_id filter, so asserting that here would pass with the community
        // exclusion removed and read as coverage it does not have.
        $this->assertSame($community->getKey(), $reply->community_id);
    }

    /**
     * @param  Collection<int, TimelinePost>|array<int, TimelinePost>  $items
     */
    private function assertFeedExcludes(TimelinePost $communityPost, TimelinePost $snsPost, $items): void
    {
        $ids = collect($items)->map->getKey()->all();

        // The SNS-wide post is asserted present in the same breath: without it a feed that returns
        // nothing at all (a broken fixture, a scope that excluded everything) would pass silently.
        $this->assertContains($snsPost->getKey(), $ids);
        $this->assertNotContains($communityPost->getKey(), $ids);
    }

    private function join(Community $community, Member $member): void
    {
        $community->members()->create(['member_id' => $member->getKey(), 'role' => CommunityRole::Member]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
