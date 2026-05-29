<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowDiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_own_private_diary(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->private()->create(['member_id' => $owner->getKey()]);

        $result = (new ShowDiary)($owner, $diary->getKey());

        $this->assertNotNull($result);
        $this->assertSame($diary->getKey(), $result->getKey());
    }

    public function test_friend_can_view_friends_level_diary(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $diary = Diary::factory()->friends()->create(['member_id' => $owner->getKey()]);

        $this->assertNotNull((new ShowDiary)($friend, $diary->getKey()));
    }

    public function test_friend_cannot_view_private_diary(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $diary = Diary::factory()->private()->create(['member_id' => $owner->getKey()]);

        $this->assertNull((new ShowDiary)($friend, $diary->getKey()));
    }

    public function test_non_friend_member_can_view_members_level_diary(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->assertNotNull((new ShowDiary)($other, $diary->getKey()));
    }

    public function test_non_friend_cannot_view_friends_level_diary(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->friends()->create(['member_id' => $owner->getKey()]);

        $this->assertNull((new ShowDiary)($other, $diary->getKey()));
    }

    public function test_returns_null_when_diary_not_found(): void
    {
        $viewer = Member::factory()->create();

        $this->assertNull((new ShowDiary)($viewer, 999999));
    }

    public function test_returns_null_when_owner_blocks_viewer(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertNull((new ShowDiary)($viewer, $diary->getKey()));
    }

    public function test_open_diary_visible_to_logged_in_viewer(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => Visibility::Open,
        ]);

        $this->assertNotNull((new ShowDiary)($other, $diary->getKey()));
    }

    public function test_open_diary_hidden_when_owner_blocks_viewer(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => Visibility::Open,
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertNull((new ShowDiary)($viewer, $diary->getKey()));
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
