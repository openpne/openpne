<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\MemberTimeline;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberTimelineTest extends TestCase
{
    use RefreshDatabase;

    // Visibility matrix ---------------------------------------------------------

    public function test_owner_sees_own_private_friends_and_members_posts(): void
    {
        $owner = Member::factory()->create();
        $this->postFor($owner, Visibility::Private);
        $this->postFor($owner, Visibility::Friends);
        $this->postFor($owner, Visibility::Members);

        $this->assertSame(3, (new MemberTimeline)($owner, $owner)->total());
    }

    public function test_friend_sees_friends_and_members_not_private(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $this->postFor($owner, Visibility::Private);
        $this->postFor($owner, Visibility::Friends);
        $this->postFor($owner, Visibility::Members);

        $this->assertSame(2, (new MemberTimeline)($friend, $owner)->total());
    }

    public function test_non_friend_member_sees_only_members_level(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->postFor($owner, Visibility::Private);
        $this->postFor($owner, Visibility::Friends);
        $this->postFor($owner, Visibility::Members);

        $this->assertSame(1, (new MemberTimeline)($other, $owner)->total());
    }

    public function test_blocked_viewer_sees_nothing(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->postFor($owner, Visibility::Members);
        $this->block($owner, $viewer);

        $this->assertSame(0, (new MemberTimeline)($viewer, $owner)->total());
    }

    public function test_owner_self_view_unaffected_by_unrelated_block(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->postFor($owner, Visibility::Private);
        $this->block($other, $owner);

        $this->assertSame(1, (new MemberTimeline)($owner, $owner)->total());
    }

    public function test_open_post_is_visible_to_all_logged_in_viewers(): void
    {
        [$owner, $friend, $other] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($owner, $friend);
        $this->postFor($owner, Visibility::Open);

        $this->assertSame(1, (new MemberTimeline)($owner, $owner)->total());
        $this->assertSame(1, (new MemberTimeline)($friend, $owner)->total());
        $this->assertSame(1, (new MemberTimeline)($other, $owner)->total());
    }

    // Top-level only ------------------------------------------------------------

    public function test_replies_are_excluded_from_the_member_timeline(): void
    {
        $owner = Member::factory()->create();
        $parent = $this->postFor($owner, Visibility::Members);
        TimelinePost::factory()->replyTo($parent)->create(['member_id' => $owner->getKey()]);

        // Only the top-level post; the reply belongs to a thread, not the stream.
        $this->assertSame(1, (new MemberTimeline)($owner, $owner)->total());
    }

    // Ordering + pagination -----------------------------------------------------

    public function test_posts_are_ordered_by_created_at_descending(): void
    {
        $owner = Member::factory()->create();
        $first = $this->postFor($owner, Visibility::Members, createdAt: '2026-01-01');
        $second = $this->postFor($owner, Visibility::Members, createdAt: '2026-03-01');

        $items = (new MemberTimeline)($owner, $owner)->items();

        $this->assertSame($second->getKey(), $items[0]->getKey());
        $this->assertSame($first->getKey(), $items[1]->getKey());
    }

    public function test_result_is_paginated(): void
    {
        $owner = Member::factory()->create();
        TimelinePost::factory()->count(25)->create(['member_id' => $owner->getKey()]);

        $result = (new MemberTimeline)($owner, $owner, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    // Helpers -------------------------------------------------------------------

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
