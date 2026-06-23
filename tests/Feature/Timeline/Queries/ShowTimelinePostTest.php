<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\ShowTimelinePost;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowTimelinePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_for_a_missing_post(): void
    {
        $viewer = Member::factory()->create();

        $this->assertNull((new ShowTimelinePost)($viewer, 999));
    }

    public function test_owner_sees_own_private_post(): void
    {
        $owner = Member::factory()->create();
        $post = TimelinePost::factory()->private()->create(['member_id' => $owner->getKey()]);

        $this->assertNotNull((new ShowTimelinePost)($owner, $post->getKey()));
    }

    public function test_non_friend_cannot_see_a_friends_only_post(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->friends()->create(['member_id' => $owner->getKey()]);

        $this->assertNull((new ShowTimelinePost)($other, $post->getKey()));
    }

    public function test_friend_sees_a_friends_only_post(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $owner->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $owner->getKey()],
        ]);
        $post = TimelinePost::factory()->friends()->create(['member_id' => $owner->getKey()]);

        $this->assertNotNull((new ShowTimelinePost)($friend, $post->getKey()));
    }

    public function test_blocked_viewer_cannot_see_an_otherwise_visible_post(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $owner->getKey()]); // Members
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertNull((new ShowTimelinePost)($viewer, $post->getKey()));
    }
}
