<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Modern diary-list calendar props (Classic x-diary.sidemenu parity): the plain list focuses the
 * current month; an archive focuses the archived month; days carry only viewer-visible diaries; the
 * prev/next targets are unbounded and wrap across a year boundary.
 */
class DiaryCalendarPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_list_calendar_focuses_the_current_month_with_wrapping_targets(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 1, 15)); // January → previous month is December 2025
        $owner = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-01-08 09:00:00',
        ]);

        $this->actingAs($owner)->get("/m/diary/listMember/{$owner->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('diary/list')
                ->where('calendar.label', '2026-01')
                ->where('calendar.year', 2026)
                ->where('calendar.month', 1)
                ->where('calendar.diaryDays', [8])
                ->where('calendar.previousMonth', ['year' => 2025, 'month' => 12])
                ->where('calendar.nextMonth', ['year' => 2026, 'month' => 2])
            );
    }

    public function test_calendar_days_are_scoped_to_viewer_visibility(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 3, 20));
        [$owner, $stranger] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-05 09:00:00']);
        Diary::factory()->private()->create(['member_id' => $owner->getKey(), 'created_at' => '2026-03-12 09:00:00']);

        // The private diary on the 12th is hidden from a non-friend, so only the 5th is linked.
        $this->actingAs($stranger)->get("/m/diary/listMember/{$owner->getKey()}")
            ->assertInertia(fn ($page) => $page->where('calendar.diaryDays', [5]));
    }

    public function test_month_archive_calendar_focuses_the_archived_month(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-14 10:00:00']);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-04-02 09:00:00']);

        $this->actingAs($owner)->get("/m/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertInertia(fn ($page) => $page
                ->where('calendar.year', 2026)
                ->where('calendar.month', 3)
                ->where('calendar.diaryDays', [14]) // April entry is outside the focused month
                ->where('calendar.previousMonth', ['year' => 2026, 'month' => 2])
                ->where('calendar.nextMonth', ['year' => 2026, 'month' => 4])
            );
    }

    public function test_day_archive_calendar_still_lists_the_whole_month(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-05 09:00:00']);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'created_at' => '2026-03-14 10:00:00']);

        // Narrowed to the 14th, but the calendar keys off the month, so both March days stay linked.
        $this->actingAs($owner)->get("/m/diary/listMember/{$owner->getKey()}/2026/3/14")
            ->assertInertia(fn ($page) => $page
                ->where('period', '2026-03-14')
                ->where('calendar.month', 3)
                ->where('calendar.diaryDays', [5, 14])
            );
    }
}
