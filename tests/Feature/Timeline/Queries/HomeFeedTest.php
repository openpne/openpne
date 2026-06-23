<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\HomeFeed;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeFeedTest extends TestCase
{
    use RefreshDatabase;

    // Audience composition (self + friends + all members) -----------------------

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

    public function test_viewer_sees_a_friends_friends_only_post_but_not_a_strangers(): void
    {
        [$viewer, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($viewer, $friend);
        $friendPost = $this->postFor($friend, Visibility::Friends);
        $strangerPost = $this->postFor($stranger, Visibility::Friends);

        $ids = $this->feedIds($viewer);
        $this->assertContains($friendPost->getKey(), $ids);
        $this->assertNotContains($strangerPost->getKey(), $ids);
    }

    public function test_viewer_sees_anyones_members_and_open_posts(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $members = $this->postFor($stranger, Visibility::Members);
        $open = $this->postFor($stranger, Visibility::Open);

        $ids = $this->feedIds($viewer);
        $this->assertContains($members->getKey(), $ids);
        $this->assertContains($open->getKey(), $ids);
    }

    public function test_viewer_never_sees_a_strangers_private_post(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $private = $this->postFor($stranger, Visibility::Private);

        $this->assertNotContains($private->getKey(), $this->feedIds($viewer));
    }

    public function test_a_friend_private_post_stays_hidden(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($viewer, $friend);
        $private = $this->postFor($friend, Visibility::Private);

        $this->assertNotContains($private->getKey(), $this->feedIds($viewer));
    }

    // Block ---------------------------------------------------------------------

    public function test_a_post_by_an_author_who_blocks_the_viewer_is_excluded(): void
    {
        [$viewer, $blocker] = Member::factory()->count(2)->create()->all();
        $post = $this->postFor($blocker, Visibility::Members);
        $this->block($blocker, $viewer);

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

    // Ordering + pagination -----------------------------------------------------

    public function test_feed_is_ordered_newest_first_with_id_tiebreaker(): void
    {
        $member = Member::factory()->create();
        $older = $this->postFor($member, Visibility::Members, createdAt: '2026-01-01 09:00:00');
        $tieA = $this->postFor($member, Visibility::Members, createdAt: '2026-03-01 12:00:00');
        $tieB = $this->postFor($member, Visibility::Members, createdAt: '2026-03-01 12:00:00');

        $ids = $this->feedIds($member);

        // Same created_at → higher id first; the older post sorts last.
        $this->assertSame([$tieB->getKey(), $tieA->getKey(), $older->getKey()], $ids);
    }

    public function test_feed_is_paginated(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->count(25)->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $result = (new HomeFeed)($member, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    // Helpers -------------------------------------------------------------------

    /** @return list<int> */
    private function feedIds(Member $viewer): array
    {
        return collect((new HomeFeed)($viewer)->items())->map->getKey()->all();
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
