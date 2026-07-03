<?php

namespace Tests\Feature\Friend\Queries;

use App\Features\Friend\Queries\RandomFriends;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RandomFriendsTest extends TestCase
{
    use RefreshDatabase;

    private function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    public function test_returns_only_the_viewers_friends(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        Member::factory()->create(); // stranger
        $this->befriend($viewer, $friend);

        $result = (new RandomFriends)($viewer);

        $this->assertCount(1, $result);
        $this->assertSame($friend->getKey(), $result->first()->getKey());
    }

    public function test_caps_at_the_limit(): void
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count(12)->create() as $friend) {
            $this->befriend($viewer, $friend);
        }

        $this->assertCount(9, (new RandomFriends)($viewer));
        $this->assertCount(3, (new RandomFriends)($viewer, 3));
    }

    public function test_is_empty_without_friends(): void
    {
        $this->assertCount(0, (new RandomFriends)(Member::factory()->create()));
    }
}
