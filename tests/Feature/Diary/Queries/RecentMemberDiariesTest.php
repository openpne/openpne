<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecentMemberDiariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_owners_recent_diaries_newest_first(): void
    {
        $owner = Member::factory()->create();
        $older = $this->diary($owner, Visibility::Members, '2026-01-01');
        $newer = $this->diary($owner, Visibility::Members, '2026-03-01');

        $result = (new RecentMemberDiaries)($owner, $owner);

        $this->assertSame([$newer->getKey(), $older->getKey()], $result->modelKeys());
    }

    public function test_caps_the_result_at_the_limit(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->count(7)->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->assertCount(5, (new RecentMemberDiaries)($owner, $owner));
        $this->assertCount(3, (new RecentMemberDiaries)($owner, $owner, limit: 3));
    }

    public function test_exposes_the_comment_count(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);
        $commenter = Member::factory()->create();
        DiaryComment::factory()->for($diary)->for($commenter, 'member')->create(['number' => 1]);
        DiaryComment::factory()->for($diary)->for($commenter, 'member')->create(['number' => 2]);

        $this->assertSame(2, (new RecentMemberDiaries)($owner, $owner)->first()->comments_count);
    }

    public function test_non_friend_sees_only_members_level(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->diary($owner, Visibility::Private);
        $this->diary($owner, Visibility::Friends);
        $this->diary($owner, Visibility::Members);

        $this->assertCount(1, (new RecentMemberDiaries)($other, $owner));
    }

    public function test_friend_sees_friends_and_members(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $this->diary($owner, Visibility::Private);
        $this->diary($owner, Visibility::Friends);
        $this->diary($owner, Visibility::Members);

        $this->assertCount(2, (new RecentMemberDiaries)($friend, $owner));
    }

    public function test_guest_sees_only_web_public(): void
    {
        $owner = Member::factory()->create();
        $this->diary($owner, Visibility::Open);
        $this->diary($owner, Visibility::Members);

        $this->assertCount(1, (new RecentMemberDiaries)(null, $owner));
    }

    public function test_blocked_viewer_sees_none(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->diary($owner, Visibility::Members);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertCount(0, (new RecentMemberDiaries)($viewer, $owner));
    }

    private function diary(Member $owner, Visibility $visibility = Visibility::Members, ?string $createdAt = null): Diary
    {
        $attrs = ['member_id' => $owner->getKey(), 'visibility' => $visibility];
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
