<?php

namespace Tests\Feature\Friend\Actions;

use App\Features\Friend\Actions\AcceptFriendRequest;
use App\Features\Friend\Actions\SendFriendRequest;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AcceptFriendRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_mirror_friendship_rows_and_clears_the_pending_request(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        (new AcceptFriendRequest)($bob, $alice);

        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertDatabaseHas('friendships', ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()]);
        $this->assertDatabaseHas('friendships', ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()]);
        Event::assertDispatched(FriendRequestAccepted::class, fn ($e) => $e->requester->is($alice) && $e->accepter->is($bob));
    }

    public function test_rejects_when_no_pending_request(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        try {
            (new AcceptFriendRequest)($bob, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::RequestNotFound, $e->reason);
        }

        $this->assertDatabaseCount('friendships', 0);
        Event::assertNotDispatched(FriendRequestAccepted::class);
    }

    public function test_rejects_when_auto_accept_already_consumed_the_pending_row(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        (new SendFriendRequest)($alice, $bob);
        (new SendFriendRequest)($bob, $alice); // auto-accepts, deletes the pending row

        try {
            (new AcceptFriendRequest)($bob, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::RequestNotFound, $e->reason);
        }

        $this->assertDatabaseCount('friendships', 2);
    }

    public function test_rolls_back_when_friendship_insert_violates_uniqueness(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);
        // Pre-existing partial-mirror row makes the bulk insert fail mid-way.
        DB::table('friendships')->insert([
            'member_id' => $alice->getKey(),
            'friend_id' => $bob->getKey(),
        ]);

        $this->expectException(QueryException::class);

        try {
            (new AcceptFriendRequest)($bob, $alice);
        } finally {
            $this->assertDatabaseHas('friend_requests', [
                'requester_id' => $alice->getKey(),
                'target_id' => $bob->getKey(),
            ]);
            $this->assertDatabaseCount('friendships', 1);
            Event::assertNotDispatched(FriendRequestAccepted::class);
        }
    }

    public function test_rejects_when_block_exists_between_pair(): void
    {
        Event::fake([FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        try {
            (new AcceptFriendRequest)($bob, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::Blocked, $e->reason);
        }

        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseCount('friend_requests', 1);
        Event::assertNotDispatched(FriendRequestAccepted::class);
    }
}
