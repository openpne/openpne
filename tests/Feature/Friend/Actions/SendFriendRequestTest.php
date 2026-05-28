<?php

namespace Tests\Feature\Friend\Actions;

use App\Features\Friend\Actions\SendFriendRequest;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Events\FriendRequested;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SendFriendRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_friend_request_row_and_dispatches_event(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new SendFriendRequest)($alice, $bob);

        $this->assertDatabaseHas('friend_requests', [
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);
        $this->assertDatabaseCount('friendships', 0);
        Event::assertDispatched(FriendRequested::class, fn ($e) => $e->requester->is($alice) && $e->target->is($bob));
        Event::assertNotDispatched(FriendRequestAccepted::class);
    }

    public function test_auto_accepts_when_reverse_request_already_exists(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        (new SendFriendRequest)($alice, $bob);

        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertDatabaseHas('friendships', ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()]);
        $this->assertDatabaseHas('friendships', ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()]);
        Event::assertDispatched(FriendRequestAccepted::class, fn ($e) => $e->requester->is($bob) && $e->accepter->is($alice));
        Event::assertNotDispatched(FriendRequested::class);
    }

    public function test_rejects_self_friend_request(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        $alice = Member::factory()->create();

        try {
            (new SendFriendRequest)($alice, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::SelfFriendship, $e->reason);
        }

        $this->assertDatabaseCount('friend_requests', 0);
        Event::assertNothingDispatched();
    }

    public function test_rejects_when_already_friends(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()],
            ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()],
        ]);

        try {
            (new SendFriendRequest)($alice, $bob);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::AlreadyFriends, $e->reason);
        }

        $this->assertDatabaseCount('friend_requests', 0);
        Event::assertNothingDispatched();
    }

    public function test_rejects_when_requester_has_blocked_target(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert([
            'blocker_id' => $alice->getKey(),
            'blocked_id' => $bob->getKey(),
        ]);

        try {
            (new SendFriendRequest)($alice, $bob);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::Blocked, $e->reason);
        }

        $this->assertDatabaseCount('friend_requests', 0);
        Event::assertNothingDispatched();
    }

    public function test_rejects_when_target_has_blocked_requester(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert([
            'blocker_id' => $bob->getKey(),
            'blocked_id' => $alice->getKey(),
        ]);

        try {
            (new SendFriendRequest)($alice, $bob);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::Blocked, $e->reason);
        }

        $this->assertDatabaseCount('friend_requests', 0);
        Event::assertNothingDispatched();
    }

    public function test_rejects_duplicate_forward_request(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        try {
            (new SendFriendRequest)($alice, $bob);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::DuplicateRequest, $e->reason);
        }

        $this->assertDatabaseCount('friend_requests', 1);
        Event::assertNothingDispatched();
    }
}
