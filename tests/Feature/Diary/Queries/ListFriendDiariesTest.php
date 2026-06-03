<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\ListFriendDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListFriendDiariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_friends_diaries_up_to_friend_visibility(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->createDiaryFor($friend, Visibility::Open);
        $this->createDiaryFor($friend, Visibility::Members);
        $this->createDiaryFor($friend, Visibility::Friends);
        $this->createDiaryFor($friend, Visibility::Private);

        $result = (new ListFriendDiaries)($viewer);

        // Open + Members + Friends, but not the friend's Private diary.
        $this->assertSame(3, $result->total());
    }

    public function test_excludes_non_friends_diaries(): void
    {
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->createDiaryFor($stranger, Visibility::Members);

        $this->assertSame(0, (new ListFriendDiaries)($viewer)->total());
    }

    public function test_is_empty_without_friends(): void
    {
        $viewer = Member::factory()->create();
        $this->createDiaryFor($viewer, Visibility::Members);

        $this->assertSame(0, (new ListFriendDiaries)($viewer)->total());
    }

    public function test_excludes_a_friend_who_blocks_the_viewer(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->createDiaryFor($friend, Visibility::Members);
        DB::table('member_blocks')->insert([
            'blocker_id' => $friend->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame(0, (new ListFriendDiaries)($viewer)->total());
    }

    public function test_orders_by_created_at_descending(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $first = $this->createDiaryFor($friend, Visibility::Members, createdAt: '2026-01-01');
        $second = $this->createDiaryFor($friend, Visibility::Members, createdAt: '2026-03-01');

        $result = (new ListFriendDiaries)($viewer);

        $this->assertSame($second->getKey(), $result->items()[0]->getKey());
        $this->assertSame($first->getKey(), $result->items()[1]->getKey());
    }

    private function createDiaryFor(Member $member, Visibility $visibility, ?string $createdAt = null): Diary
    {
        $attrs = ['member_id' => $member->getKey(), 'visibility' => $visibility];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return Diary::factory()->create($attrs);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
