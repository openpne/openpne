<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\ArchivePeriod;
use App\Features\Diary\Queries\ListDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListDiariesTest extends TestCase
{
    use RefreshDatabase;

    // Visibility matrix ---------------------------------------------------------

    public function test_owner_sees_own_private_friends_and_members_diaries(): void
    {
        $owner = Member::factory()->create();
        $this->createDiaryFor($owner, Visibility::Private);
        $this->createDiaryFor($owner, Visibility::Friends);
        $this->createDiaryFor($owner, Visibility::Members);

        $result = (new ListDiaries)($owner, $owner);

        $this->assertSame(3, $result->total());
    }

    public function test_friend_sees_friends_and_members_not_private(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $this->createDiaryFor($owner, Visibility::Private);
        $this->createDiaryFor($owner, Visibility::Friends);
        $this->createDiaryFor($owner, Visibility::Members);

        $result = (new ListDiaries)($friend, $owner);

        $this->assertSame(2, $result->total());
    }

    public function test_non_friend_member_sees_only_members_level(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($owner, Visibility::Private);
        $this->createDiaryFor($owner, Visibility::Friends);
        $this->createDiaryFor($owner, Visibility::Members);

        $result = (new ListDiaries)($other, $owner);

        $this->assertSame(1, $result->total());
    }

    public function test_blocked_viewer_sees_nothing(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($owner, Visibility::Members);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $result = (new ListDiaries)($viewer, $owner);

        $this->assertSame(0, $result->total());
    }

    public function test_owner_self_view_unaffected_by_unrelated_block(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($owner, Visibility::Private);
        DB::table('member_blocks')->insert([
            'blocker_id' => $other->getKey(),
            'blocked_id' => $owner->getKey(),
        ]);

        $result = (new ListDiaries)($owner, $owner);

        $this->assertSame(1, $result->total());
    }

    // Open=0 invariant ----------------------------------------------------------

    public function test_open_diary_inserted_directly_is_visible_to_all_logged_in_viewers(): void
    {
        [$owner, $friend, $other] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($owner, $friend);
        // Open is not selectable via form; insert directly.
        $this->createDiaryFor($owner, Visibility::Open);

        $this->assertSame(1, (new ListDiaries)($owner, $owner)->total());
        $this->assertSame(1, (new ListDiaries)($friend, $owner)->total());
        $this->assertSame(1, (new ListDiaries)($other, $owner)->total());
    }

    public function test_open_diary_hidden_when_owner_blocks_viewer(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($owner, Visibility::Open);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame(0, (new ListDiaries)($viewer, $owner)->total());
    }

    // Ordering + pagination -----------------------------------------------------

    public function test_diaries_are_ordered_by_created_at_descending(): void
    {
        $owner = Member::factory()->create();
        $first = $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-01-01');
        $second = $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-03-01');

        $result = (new ListDiaries)($owner, $owner);

        $this->assertSame($second->getKey(), $result->items()[0]->getKey());
        $this->assertSame($first->getKey(), $result->items()[1]->getKey());
    }

    public function test_result_is_paginated(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->count(25)->create(['member_id' => $owner->getKey()]);

        $result = (new ListDiaries)($owner, $owner, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    // Calendar archive (date filter) --------------------------------------------

    public function test_month_period_filters_to_that_month(): void
    {
        $owner = Member::factory()->create();
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-03-10 09:00:00');
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-02-28 09:00:00');
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-04-01 00:00:00');

        $result = (new ListDiaries)($owner, $owner, period: ArchivePeriod::fromYearMonthDay(2026, 3));

        // Only the March entry: the half-open range excludes the 2026-04-01 00:00 boundary.
        $this->assertSame(1, $result->total());
    }

    public function test_day_period_filters_to_that_day(): void
    {
        $owner = Member::factory()->create();
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-03-15 23:59:59');
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-03-16 00:00:00');

        $result = (new ListDiaries)($owner, $owner, period: ArchivePeriod::fromYearMonthDay(2026, 3, 15));

        $this->assertSame(1, $result->total());
    }

    public function test_visibility_still_applies_within_a_period(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($owner, Visibility::Private, createdAt: '2026-03-10 09:00:00');
        $this->createDiaryFor($owner, Visibility::Members, createdAt: '2026-03-11 09:00:00');

        $result = (new ListDiaries)($other, $owner, period: ArchivePeriod::fromYearMonthDay(2026, 3));

        // A non-friend sees only the Members entry, even inside the archived month.
        $this->assertSame(1, $result->total());
    }

    // Helpers -------------------------------------------------------------------

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
