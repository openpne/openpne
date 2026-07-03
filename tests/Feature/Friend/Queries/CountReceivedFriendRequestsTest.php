<?php

namespace Tests\Feature\Friend\Queries;

use App\Features\Friend\Queries\CountReceivedFriendRequests;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CountReceivedFriendRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function request(Member $requester, Member $target): void
    {
        DB::table('friend_requests')->insert([
            'requester_id' => $requester->getKey(),
            'target_id' => $target->getKey(),
        ]);
    }

    public function test_counts_only_requests_received_by_the_viewer(): void
    {
        [$viewer, $a, $b] = Member::factory()->count(3)->create()->all();

        $this->request($a, $viewer);          // received: counts
        $this->request($b, $viewer);          // received: counts
        $this->request($viewer, $a);          // sent by viewer: excluded

        $this->assertSame(2, (new CountReceivedFriendRequests)($viewer));
    }

    public function test_is_zero_without_requests(): void
    {
        $viewer = Member::factory()->create();

        $this->assertSame(0, (new CountReceivedFriendRequests)($viewer));
    }
}
