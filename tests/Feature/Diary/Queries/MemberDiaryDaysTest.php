<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\MemberDiaryDays;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberDiaryDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_days_in_the_month_with_a_diary_deduped_and_sorted(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-10 09:00:00');
        $this->diaryOn($owner, '2026-03-10 21:00:00'); // same day, counts once
        $this->diaryOn($owner, '2026-03-02 09:00:00');

        $this->assertSame([2, 10], (new MemberDiaryDays)($owner, $owner, 2026, 3));
    }

    public function test_excludes_other_months(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-15 09:00:00');
        $this->diaryOn($owner, '2026-04-01 09:00:00');
        $this->diaryOn($owner, '2026-02-28 09:00:00');

        $this->assertSame([15], (new MemberDiaryDays)($owner, $owner, 2026, 3));
    }

    public function test_is_scoped_to_the_viewer(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->diaryOn($owner, '2026-03-05 09:00:00', Visibility::Members);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Private);

        // A non-friend sees only the Members-level day, not the private one.
        $this->assertSame([5], (new MemberDiaryDays)($other, $owner, 2026, 3));
    }

    public function test_guest_sees_only_web_public_days(): void
    {
        $owner = Member::factory()->create();
        $this->diaryOn($owner, '2026-03-05 09:00:00', Visibility::Open);
        $this->diaryOn($owner, '2026-03-20 09:00:00', Visibility::Members);

        $this->assertSame([5], (new MemberDiaryDays)(null, $owner, 2026, 3));
    }

    public function test_blocked_viewer_sees_no_days(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->diaryOn($owner, '2026-03-05 09:00:00');
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame([], (new MemberDiaryDays)($viewer, $owner, 2026, 3));
    }

    private function diaryOn(Member $owner, string $createdAt, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => $visibility,
            'created_at' => $createdAt,
        ]);
    }
}
