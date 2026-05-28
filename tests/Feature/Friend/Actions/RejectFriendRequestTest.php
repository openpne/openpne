<?php

namespace Tests\Feature\Friend\Actions;

use App\Features\Friend\Actions\RejectFriendRequest;
use App\Features\Friend\Events\FriendRequestAccepted;
use App\Features\Friend\Events\FriendRequested;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RejectFriendRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_the_pending_request_without_dispatching_events(): void
    {
        Event::fake([FriendRequested::class, FriendRequestAccepted::class]);
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
        ]);

        (new RejectFriendRequest)($bob, $alice);

        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertDatabaseCount('friendships', 0);
        Event::assertNotDispatched(FriendRequested::class);
        Event::assertNotDispatched(FriendRequestAccepted::class);
    }

    public function test_rejects_when_no_pending_request(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        try {
            (new RejectFriendRequest)($bob, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::RequestNotFound, $e->reason);
        }
    }
}
