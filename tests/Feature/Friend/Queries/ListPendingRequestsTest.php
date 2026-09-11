<?php

namespace Tests\Feature\Friend\Queries;

use App\Features\Friend\Queries\ListPendingRequests;
use App\Features\Friend\Queries\PendingRequestDirection;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
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

    public function test_both_directions_split_one_second_at_the_other_key_with_no_row_repeated_or_lost(): void
    {
        $alice = Member::factory()->create();
        $others = Member::factory()->count(25)->create();
        DB::table('friend_requests')->insert($others->map(fn (Member $m) => [
            'requester_id' => $m->getKey(), 'target_id' => $alice->getKey(), 'created_at' => '2026-03-01 12:00:00',
        ])->all());
        $expected = array_reverse($others->modelKeys());

        $received = fn (int $page) => $this->onPage($page, fn () => (new ListPendingRequests)($alice, PendingRequestDirection::Received))->getCollection()->modelKeys();
        $this->assertSame(array_slice($expected, 0, 20), $received(1));
        $this->assertSame(array_slice($expected, 20), $received(2));

        DB::table('friend_requests')->delete();
        DB::table('friend_requests')->insert($others->map(fn (Member $m) => [
            'requester_id' => $alice->getKey(), 'target_id' => $m->getKey(), 'created_at' => '2026-03-01 12:00:00',
        ])->all());
        $sent = fn (int $page) => $this->onPage($page, fn () => (new ListPendingRequests)($alice, PendingRequestDirection::Sent))->getCollection()->modelKeys();

        $this->assertSame(array_slice($expected, 0, 20), $sent(1));
        $this->assertSame(array_slice($expected, 20), $sent(2));
    }

    public function test_paginator_uses_custom_page_name(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->create();
        DB::table('friend_requests')->insert([
            'requester_id' => $bob->getKey(),
            'target_id' => $alice->getKey(),
        ]);

        $page = (new ListPendingRequests)(
            $alice,
            PendingRequestDirection::Received,
            pageName: 'received_page',
        );

        $this->assertStringContainsString('received_page=', $page->url(2));
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

    private function onPage(int $page, callable $run): mixed
    {
        Paginator::currentPageResolver(fn () => $page);
        try {
            return $run();
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
    }
}
