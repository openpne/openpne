<?php

namespace Tests\Feature\Friend\Queries;

use App\Features\Friend\Queries\ListFriends;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListFriendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_owner_friendships(): void
    {
        [$alice, $bob, $carol, $stranger] = Member::factory()->count(4)->create()->all();
        $this->makeFriends($alice, $bob);
        $this->makeFriends($alice, $carol);

        $page = (new ListFriends)($alice, $alice);

        $this->assertSame(2, $page->total());
        $ids = collect($page->items())->map(fn ($m) => $m->getKey())->all();
        $this->assertContains($bob->getKey(), $ids);
        $this->assertContains($carol->getKey(), $ids);
        $this->assertNotContains($stranger->getKey(), $ids);
    }

    public function test_paginates(): void
    {
        $owner = Member::factory()->create();
        $friends = Member::factory()->count(5)->create();
        foreach ($friends as $f) {
            $this->makeFriends($owner, $f);
        }

        $page = (new ListFriends)($owner, $owner, perPage: 2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertSame(3, $page->lastPage());
    }

    public function test_works_when_viewer_is_not_owner(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($alice, $carol);

        $page = (new ListFriends)($bob, $alice);

        $this->assertSame(1, $page->total());
    }

    public function test_returns_empty_when_owner_has_blocked_viewer(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($alice, $carol);
        DB::table('member_blocks')->insert([
            'blocker_id' => $alice->getKey(),
            'blocked_id' => $bob->getKey(),
        ]);

        $page = (new ListFriends)($bob, $alice);

        $this->assertSame(0, $page->total());
    }

    public function test_viewer_blocked_by_owner_does_not_affect_owner_self_view(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($alice, $carol);
        DB::table('member_blocks')->insert([
            'blocker_id' => $alice->getKey(),
            'blocked_id' => $bob->getKey(),
        ]);

        $page = (new ListFriends)($alice, $alice);

        $this->assertSame(1, $page->total());
    }

    /**
     * Friendships made in the same request share a timestamp (`created_at` defaults to useCurrent), so
     * the tie-break is what the decorative row's "same set on every visit" actually rests on.
     */
    public function test_take_newest_breaks_a_tie_on_the_friend_id(): void
    {
        [$owner, $early, $late] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($owner, $early, '2026-08-01 10:00:00');
        $this->makeFriends($owner, $late, '2026-08-01 10:00:00');

        $friends = (new ListFriends)->takeNewest($owner, $owner, 2);

        $this->assertSame([$late->getKey(), $early->getKey()], $friends->modelKeys());
    }

    public function test_take_newest_returns_empty_when_owner_has_blocked_viewer(): void
    {
        [$alice, $bob, $carol] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($alice, $carol);
        DB::table('member_blocks')->insert([
            'blocker_id' => $alice->getKey(),
            'blocked_id' => $bob->getKey(),
        ]);

        $this->assertSame([], (new ListFriends)->takeNewest($bob, $alice, 9)->modelKeys());
    }

    /** `$at` defaults to useCurrent, which is what production writes. */
    private function makeFriends(Member $a, Member $b, ?string $at = null): void
    {
        $when = $at === null ? [] : ['created_at' => $at];

        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey(), ...$when],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey(), ...$when],
        ]);
    }
}
