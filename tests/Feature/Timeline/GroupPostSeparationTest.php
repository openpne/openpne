<?php

namespace Tests\Feature\Timeline;

use App\Features\Group\GroupRole;
use App\Features\Profile\Queries\ProfileStats;
use App\Features\Timeline\Queries\AllMemberFeed;
use App\Features\Timeline\Queries\FriendFeed;
use App\Features\Timeline\Queries\HomeFeed;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Queries\TagFeed;
use App\Models\Group;
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
class GroupPostSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_community_post_is_absent_from_every_sns_wide_feed(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $author);

        $group = Group::factory()->create();
        $this->join($group, $viewer);
        $this->join($group, $author);

        $groupPost = TimelinePost::factory()->inGroup($group)->create([
            'member_id' => $author->getKey(),
            'body' => 'community only #tag',
        ]);
        $groupPost->tags()->create(['tag' => 'tag', 'offset' => 15, 'length' => 4]);

        $snsPost = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'body' => 'sns wide #tag',
        ]);
        $snsPost->tags()->create(['tag' => 'tag', 'offset' => 9, 'length' => 4]);

        $this->assertFeedExcludes($groupPost, $snsPost, (new HomeFeed)($viewer)->items());
        $this->assertFeedExcludes($groupPost, $snsPost, (new AllMemberFeed)($viewer)->items());
        $this->assertFeedExcludes($groupPost, $snsPost, (new FriendFeed)($viewer)->items());
        $this->assertFeedExcludes($groupPost, $snsPost, (new TagFeed)($viewer, 'tag')->items());
        $this->assertFeedExcludes($groupPost, $snsPost, (new MemberTimeline)($viewer, $author)->items());
    }

    public function test_the_author_does_not_see_their_own_community_post_in_their_timeline(): void
    {
        $author = Member::factory()->create();
        $group = Group::factory()->create();
        $this->join($group, $author);

        $groupPost = TimelinePost::factory()->inGroup($group)->create(['member_id' => $author->getKey()]);

        // Own posts are the widest branch in both scopes (every visibility, no clearance check), so
        // this is where an exclusion applied to the audience instead of the query would leak.
        $this->assertNotContains(
            $groupPost->getKey(),
            collect((new MemberTimeline)($author, $author)->items())->map->getKey()->all(),
        );
        $this->assertNotContains(
            $groupPost->getKey(),
            collect((new HomeFeed)($author)->items())->map->getKey()->all(),
        );
    }

    public function test_profile_activity_count_ignores_community_posts(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create();
        $this->join($group, $author);

        TimelinePost::factory()->inGroup($group)->count(3)->create(['member_id' => $author->getKey()]);
        TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $this->assertSame(1, (new ProfileStats)($viewer, $author)['activity']);
    }

    public function test_a_community_posts_hashtag_is_not_linked(): void
    {
        // The tag page is SNS-wide and excludes community posts, so linking one would open a page
        // that does not contain the post the reader clicked it from. Both surfaces read
        // linkableTags(), so this pins the rule for the Blade component and the serializer at once.
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);

        $post = TimelinePost::factory()->inGroup($group)->create([
            'member_id' => $author->getKey(),
            'body' => 'tagged #here',
        ]);
        $post->tags()->create(['tag' => 'here', 'offset' => 7, 'length' => 5]);

        $snsPost = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'body' => 'tagged #here']);
        $snsPost->tags()->create(['tag' => 'here', 'offset' => 7, 'length' => 5]);

        $this->assertCount(0, $post->fresh()->linkableTags());
        $this->assertCount(1, $snsPost->fresh()->linkableTags());
        // The rows are still stored — the tag is searchable data, only its link has no destination.
        $this->assertCount(1, $post->fresh()->tags);
    }

    /**
     * @param  Collection<int, TimelinePost>|array<int, TimelinePost>  $items
     */
    private function assertFeedExcludes(TimelinePost $groupPost, TimelinePost $snsPost, $items): void
    {
        $ids = collect($items)->map->getKey()->all();

        // The SNS-wide post is asserted present in the same breath: without it a feed that returns
        // nothing at all (a broken fixture, a scope that excluded everything) would pass silently.
        $this->assertContains($snsPost->getKey(), $ids);
        $this->assertNotContains($groupPost->getKey(), $ids);
    }

    private function join(Group $group, Member $member): void
    {
        $group->members()->create(['member_id' => $member->getKey(), 'role' => GroupRole::Member]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
