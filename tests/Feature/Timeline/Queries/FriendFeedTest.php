<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\FriendFeed;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FriendFeedTest extends TestCase
{
    use RefreshDatabase;

    // Audience composition (self + friends, no all-members tier) -----------------

    public function test_viewer_sees_their_own_posts_at_every_visibility(): void
    {
        $viewer = Member::factory()->create();
        $private = $this->postFor($viewer, Visibility::Private);
        $friends = $this->postFor($viewer, Visibility::Friends);
        $members = $this->postFor($viewer, Visibility::Members);
        $open = $this->postFor($viewer, Visibility::Open);

        $this->assertEqualsCanonicalizing(
            [$private->getKey(), $friends->getKey(), $members->getKey(), $open->getKey()],
            $this->feedIds($viewer),
        );
    }

    public function test_viewer_sees_a_friends_friends_only_post(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $friend);
        $friendPost = $this->postFor($friend, Visibility::Friends);

        $this->assertContains($friendPost->getKey(), $this->feedIds($viewer));
    }

    public function test_a_non_friends_members_post_is_excluded_unlike_the_home_feed(): void
    {
        [$viewer, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($viewer, $friend);
        $friendMembers = $this->postFor($friend, Visibility::Members);
        $strangerMembers = $this->postFor($stranger, Visibility::Members);

        $ids = $this->feedIds($viewer);
        // A friend's all-members post is in; the stranger's is not — this is the HomeFeed difference.
        $this->assertContains($friendMembers->getKey(), $ids);
        $this->assertNotContains($strangerMembers->getKey(), $ids);
    }

    public function test_a_friends_private_post_stays_hidden(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $friend);
        $private = $this->postFor($friend, Visibility::Private);

        $this->assertNotContains($private->getKey(), $this->feedIds($viewer));
    }

    // Block ---------------------------------------------------------------------

    public function test_a_post_by_a_friend_who_blocks_the_viewer_is_excluded(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $friend);
        $post = $this->postFor($friend, Visibility::Friends);
        $this->block($friend, $viewer);

        $this->assertNotContains($post->getKey(), $this->feedIds($viewer));
    }

    // Top-level only ------------------------------------------------------------

    public function test_replies_are_excluded_from_the_feed(): void
    {
        $member = Member::factory()->create();
        $parent = $this->postFor($member, Visibility::Members);
        $reply = TimelinePost::factory()->replyTo($parent)->create(['member_id' => $member->getKey()]);

        $ids = $this->feedIds($member);
        $this->assertContains($parent->getKey(), $ids);
        $this->assertNotContains($reply->getKey(), $ids);
    }

    // take() + pagination -------------------------------------------------------

    public function test_take_returns_a_limited_collection_newest_first(): void
    {
        $member = Member::factory()->create();
        $older = $this->postFor($member, Visibility::Members, createdAt: '2026-01-01 09:00:00');
        $newer = $this->postFor($member, Visibility::Members, createdAt: '2026-03-01 09:00:00');

        $posts = (new FriendFeed)->take($member, 1);

        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertSame([$newer->getKey()], $posts->modelKeys());
        $this->assertNotContains($older->getKey(), $posts->modelKeys());
    }

    public function test_invoke_is_paginated(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->count(25)->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $result = (new FriendFeed)($member, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    // Helpers -------------------------------------------------------------------

    /** @return list<int> */
    private function feedIds(Member $viewer): array
    {
        return collect((new FriendFeed)($viewer)->items())->map->getKey()->all();
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
