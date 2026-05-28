<?php

namespace Tests\Feature\MemberRelationship;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelationAccessorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_friendships_relation_returns_both_mirror_directions(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        DB::table('friendships')->insert([
            ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()],
            ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()],
        ]);

        $this->assertSame(1, $alice->friendships()->count());
        $this->assertSame(1, $bob->friendships()->count());
        $this->assertTrue($alice->isFriendsWith($bob));
        $this->assertTrue($bob->isFriendsWith($alice));
    }

    public function test_friend_request_relations_distinguish_direction(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        $this->assertSame(1, $alice->friendRequestsSent()->count());
        $this->assertSame(0, $alice->friendRequestsReceived()->count());
        $this->assertSame(0, $bob->friendRequestsSent()->count());
        $this->assertSame(1, $bob->friendRequestsReceived()->count());

        $this->assertTrue($bob->hasPendingRequestFrom($alice));
        $this->assertFalse($alice->hasPendingRequestFrom($bob));
    }

    public function test_block_relations_distinguish_direction(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        DB::table('member_blocks')->insert([
            'blocker_id' => $alice->getKey(),
            'blocked_id' => $bob->getKey(),
        ]);

        $this->assertSame(1, $alice->blocksMade()->count());
        $this->assertSame(0, $alice->blocksReceived()->count());
        $this->assertSame(0, $bob->blocksMade()->count());
        $this->assertSame(1, $bob->blocksReceived()->count());
    }

    public function test_relations_eager_load_without_n_plus_one(): void
    {
        $members = Member::factory()->count(5)->create();
        $primary = $members->first();
        $rest = $members->skip(1);

        foreach ($rest as $other) {
            DB::table('friendships')->insert([
                ['member_id' => $primary->getKey(), 'friend_id' => $other->getKey()],
                ['member_id' => $other->getKey(), 'friend_id' => $primary->getKey()],
            ]);
        }

        DB::connection()->enableQueryLog();

        $loaded = Member::with([
            'friendships',
            'friendRequestsSent',
            'friendRequestsReceived',
            'blocksMade',
            'blocksReceived',
        ])->get();

        $queries = DB::connection()->getQueryLog();

        $this->assertCount(5, $loaded);
        $this->assertLessThanOrEqual(6, count($queries));

        DB::connection()->flushQueryLog();

        foreach ($loaded as $member) {
            $member->friendships->count();
            $member->friendRequestsSent->count();
            $member->friendRequestsReceived->count();
            $member->blocksMade->count();
            $member->blocksReceived->count();
        }

        $afterTouch = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertCount(0, $afterTouch, 'Touching eager-loaded relations fired additional queries');
    }
}
