<?php

namespace Tests\Feature\Friend\Actions;

use App\Features\Friend\Actions\Unfriend;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnfriendTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_both_mirror_rows_atomically(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()],
            ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()],
        ]);

        (new Unfriend)($alice, $bob);

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_works_regardless_of_argument_order(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $alice->getKey(), 'friend_id' => $bob->getKey()],
            ['member_id' => $bob->getKey(), 'friend_id' => $alice->getKey()],
        ]);

        (new Unfriend)($bob, $alice);

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_rejects_when_not_friends(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        try {
            (new Unfriend)($alice, $bob);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::NotFriends, $e->reason);
        }
    }

    public function test_rejects_self_unfriend(): void
    {
        $alice = Member::factory()->create();

        try {
            (new Unfriend)($alice, $alice);
            $this->fail('expected FriendActionException');
        } catch (FriendActionException $e) {
            $this->assertSame(FriendActionFailure::NotFriends, $e->reason);
        }
    }
}
