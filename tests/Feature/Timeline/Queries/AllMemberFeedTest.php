<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\AllMemberFeed;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AllMemberFeedTest extends TestCase
{
    use RefreshDatabase;

    // Audience composition (members-only, no viewer-specific tiers) ---------------

    public function test_a_non_friends_members_post_is_included(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $post = $this->postFor($stranger, Visibility::Members);

        $this->assertContains($post->getKey(), $this->feedIds($viewer));
    }

    public function test_a_web_public_post_is_included(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $post = $this->postFor($stranger, Visibility::Open);

        $this->assertContains($post->getKey(), $this->feedIds($viewer));
    }

    public function test_the_viewers_own_private_post_is_excluded_unlike_the_home_feed(): void
    {
        $viewer = Member::factory()->create();
        $private = $this->postFor($viewer, Visibility::Private);
        $members = $this->postFor($viewer, Visibility::Members);

        $ids = $this->feedIds($viewer);
        // HomeFeed/FriendFeed would include the viewer's own Private; the all-members feed does not.
        $this->assertNotContains($private->getKey(), $ids);
        $this->assertContains($members->getKey(), $ids);
    }

    public function test_a_friends_friends_only_post_is_excluded_unlike_the_home_feed(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $friend);
        $friendsOnly = $this->postFor($friend, Visibility::Friends);
        $membersPost = $this->postFor($friend, Visibility::Members);

        $ids = $this->feedIds($viewer);
        // HomeFeed would add a friend's friends-only post; the all-members feed shows only members-tier.
        $this->assertNotContains($friendsOnly->getKey(), $ids);
        $this->assertContains($membersPost->getKey(), $ids);
    }

    // Block ---------------------------------------------------------------------

    public function test_a_post_by_an_author_who_blocks_the_viewer_is_excluded(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $post = $this->postFor($author, Visibility::Members);
        $this->block($author, $viewer);

        $this->assertNotContains($post->getKey(), $this->feedIds($viewer));
    }

    // Top-level only ------------------------------------------------------------

    public function test_replies_are_excluded_from_the_feed(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $parent = $this->postFor($author, Visibility::Members);
        $reply = TimelinePost::factory()->replyTo($parent)->create(['member_id' => $author->getKey()]);

        $ids = $this->feedIds($viewer);
        $this->assertContains($parent->getKey(), $ids);
        $this->assertNotContains($reply->getKey(), $ids);
    }

    // take() + pagination -------------------------------------------------------

    public function test_take_returns_a_limited_collection_newest_first(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $older = $this->postFor($author, Visibility::Members, createdAt: '2026-01-01 09:00:00');
        $newer = $this->postFor($author, Visibility::Members, createdAt: '2026-03-01 09:00:00');

        $posts = (new AllMemberFeed)->take($viewer, 1);

        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertSame([$newer->getKey()], $posts->modelKeys());
        $this->assertNotContains($older->getKey(), $posts->modelKeys());
    }

    public function test_invoke_is_paginated(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        TimelinePost::factory()->count(25)->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $result = (new AllMemberFeed)($viewer, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    // Helpers -------------------------------------------------------------------

    /** @return list<int> */
    private function feedIds(Member $viewer): array
    {
        return collect((new AllMemberFeed)($viewer)->items())->map->getKey()->all();
    }

    private function postFor(Member $member, Visibility $visibility, ?string $createdAt = null): TimelinePost
    {
        $attrs = ['member_id' => $member->getKey(), 'visibility' => $visibility];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return TimelinePost::factory()->create($attrs);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
        ]);
    }
}
