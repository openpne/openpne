<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\MemberDiaryMonthlyCounts;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberDiaryMonthlyCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_visible_diaries_per_month_newest_first(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-10 09:00:00');
        $this->diaryOn($owner, '2026-03-02 21:00:00'); // same month, counted twice
        $this->diaryOn($owner, '2026-01-15 09:00:00');
        $this->diaryOn($owner, '2025-12-31 09:00:00'); // prior year, separate bucket

        $this->assertSame(
            [
                ['year' => 2026, 'month' => 3, 'count' => 2],
                ['year' => 2026, 'month' => 1, 'count' => 1],
                ['year' => 2025, 'month' => 12, 'count' => 1],
            ],
            (new MemberDiaryMonthlyCounts)($owner, $owner),
        );
    }

    public function test_owner_counts_include_private_entries(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-10 09:00:00', Visibility::Private);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Members);

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 2]],
            (new MemberDiaryMonthlyCounts)($owner, $owner),
        );
    }

    public function test_non_friend_viewer_counts_only_up_to_members_level(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->diaryOn($owner, '2026-03-05 09:00:00', Visibility::Members);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Private);

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 1]],
            (new MemberDiaryMonthlyCounts)($other, $owner),
        );
    }

    public function test_friend_viewer_counts_up_to_friends_level(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $owner->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $owner->getKey()],
        ]);
        $this->diaryOn($owner, '2026-03-05 09:00:00', Visibility::Friends);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Private);

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 1]],
            (new MemberDiaryMonthlyCounts)($friend, $owner),
        );
    }

    public function test_guest_counts_only_web_public_entries(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-05 09:00:00', Visibility::Open);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Members);

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 1]],
            (new MemberDiaryMonthlyCounts)(null, $owner),
        );
    }

    public function test_blocked_viewer_counts_nothing(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->diaryOn($owner, '2026-03-05 09:00:00');
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame([], (new MemberDiaryMonthlyCounts)($viewer, $owner));
    }

    public function test_months_without_a_visible_diary_are_absent(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-05 09:00:00');

        $result = (new MemberDiaryMonthlyCounts)($owner, $owner);
        $this->assertSame([['year' => 2026, 'month' => 3, 'count' => 1]], $result);
    }

    public function test_keyword_filters_month_counts_by_title(): void
    {
        $owner = Member::factory()->create();
        $this->keyed($owner, 'laravel', 'x', '2026-03-10 09:00:00');
        $this->keyed($owner, 'cooking', 'y', '2026-03-20 09:00:00'); // same month, no match
        $this->keyed($owner, 'laravel', 'z', '2026-01-05 09:00:00'); // earlier month, matches

        $this->assertSame(
            [
                ['year' => 2026, 'month' => 3, 'count' => 1],
                ['year' => 2026, 'month' => 1, 'count' => 1],
            ],
            (new MemberDiaryMonthlyCounts)($owner, $owner, 'laravel'),
        );
    }

    public function test_keyword_matches_body_for_month_counts(): void
    {
        $owner = Member::factory()->create();
        $this->keyed($owner, 'Cooking', 'I love laravel', '2026-03-10 09:00:00');
        $this->keyed($owner, 'Cooking', 'nothing here', '2026-03-20 09:00:00');

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 1]],
            (new MemberDiaryMonthlyCounts)($owner, $owner, 'laravel'),
        );
    }

    public function test_keyword_counts_respect_viewer_scope(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        // Both carry the term, but the private one is invisible to a non-friend viewer.
        $this->keyed($owner, 'laravel', 'x', '2026-03-05 09:00:00', Visibility::Members);
        $this->keyed($owner, 'laravel', 'y', '2026-03-20 09:00:00', Visibility::Private);

        $this->assertSame(
            [['year' => 2026, 'month' => 3, 'count' => 1]],
            (new MemberDiaryMonthlyCounts)($other, $owner, 'laravel'),
        );
    }

    private function diaryOn(Member $owner, string $createdAt, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => $visibility,
            'created_at' => $createdAt,
        ]);
    }

    private function keyed(Member $owner, string $title, string $body, string $createdAt, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'title' => $title,
            'body' => $body,
            'visibility' => $visibility,
            'created_at' => $createdAt,
        ]);
    }
}
