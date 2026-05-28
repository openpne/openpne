<?php

namespace Tests\Feature\Friend\Queries;

use App\Features\Friend\Queries\ListPendingRequests;
use App\Features\Friend\Queries\PendingRequestDirection;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListPendingRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_received_requests(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        DB::table('friend_requests')->insert([
            ['requester_id' => $bob->getKey(), 'target_id' => $alice->getKey()],
            ['requester_id' => $carol->getKey(), 'target_id' => $alice->getKey()],
        ]);

        $page = (new ListPendingRequests)($alice, PendingRequestDirection::Received);

        $this->assertSame(2, $page->total());
        $ids = collect($page->items())->map(fn ($m) => $m->getKey())->all();
        $this->assertEqualsCanonicalizing([$bob->getKey(), $carol->getKey()], $ids);
    }

    public function test_returns_sent_requests(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        DB::table('friend_requests')->insert([
            ['requester_id' => $alice->getKey(), 'target_id' => $bob->getKey()],
            ['requester_id' => $alice->getKey(), 'target_id' => $carol->getKey()],
        ]);

        $page = (new ListPendingRequests)($alice, PendingRequestDirection::Sent);

        $this->assertSame(2, $page->total());
        $ids = collect($page->items())->map(fn ($m) => $m->getKey())->all();
        $this->assertEqualsCanonicalizing([$bob->getKey(), $carol->getKey()], $ids);
    }

    public function test_sent_and_received_directions_do_not_bleed_into_each_other(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            ['requester_id' => $alice->getKey(), 'target_id' => $bob->getKey()],
        ]);

        $this->assertSame(1, (new ListPendingRequests)($alice, PendingRequestDirection::Sent)->total());
        $this->assertSame(0, (new ListPendingRequests)($alice, PendingRequestDirection::Received)->total());
        $this->assertSame(0, (new ListPendingRequests)($bob, PendingRequestDirection::Sent)->total());
        $this->assertSame(1, (new ListPendingRequests)($bob, PendingRequestDirection::Received)->total());
    }
}
