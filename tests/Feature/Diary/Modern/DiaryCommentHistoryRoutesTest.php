<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryCommentHistoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function commentedOn(Member $viewer, array $diary = [], ?string $at = null): Diary
    {
        $diary = Diary::factory()->create(['visibility' => Visibility::Members, ...$diary]);
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => $viewer->getKey(),
            ...($at === null ? [] : ['created_at' => Carbon::parse($at)]),
        ]);

        return $diary;
    }

    public function test_the_page_lists_the_commented_diaries_last_comment_first(): void
    {
        $viewer = Member::factory()->create();
        $older = $this->commentedOn($viewer, ['created_at' => Carbon::parse('2026-03-02 09:00:00')], '2026-03-03 09:00:00');
        $newer = $this->commentedOn($viewer, ['created_at' => Carbon::parse('2026-03-01 09:00:00')], '2026-03-04 09:00:00');
        DiaryImage::factory()->create(['diary_id' => $newer->getKey()]);
        Diary::factory()->create(['visibility' => Visibility::Members]); // never commented on

        $this->actingAs($viewer)->get('/diary/comment/history')->assertInertia(fn ($page) => $page
            ->component('diary/feed')
            ->where('variant', 'commented')
            ->where('searchable', false)
            ->has('diaries.data', 2)
            ->where('diaries.data.0.id', $newer->getKey())
            ->where('diaries.data.1.id', $older->getKey())
            // The row's date is the comment's, the one the order is made of, not the diary's.
            ->where('diaries.data.0.createdAt', Carbon::parse('2026-03-04 09:00:00')->toIso8601String())
            ->has('diaries.data.0.thumbnails', 1)
            ->where('diaries.meta.total', 2)
        );
    }

    public function test_a_diary_the_viewer_may_no_longer_read_is_left_out(): void
    {
        $viewer = Member::factory()->create();
        $this->commentedOn($viewer, ['visibility' => Visibility::Private]);
        $blocker = Member::factory()->create();
        $this->commentedOn($viewer, ['member_id' => $blocker->getKey()]);
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $viewer->getKey(), 'created_at' => now()]);

        $this->actingAs($viewer)->get('/diary/comment/history')
            ->assertInertia(fn ($page) => $page->where('diaries.data', []));
    }

    public function test_the_page_costs_the_same_for_two_rows_as_for_twenty(): void
    {
        $viewer = Member::factory()->create();
        $this->actingAs($viewer);
        $row = function () use ($viewer): void {
            $diary = $this->commentedOn($viewer, ['member_id' => Member::factory()->create()->getKey()]);
            DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        };

        $row();
        $row();
        $this->get('/diary/comment/history')->assertOk(); // the first request pays the sign-in write and the cold caches
        $two = $this->countApplicationQueries(fn () => $this->get('/diary/comment/history')->assertOk());

        foreach (range(1, 18) as $ignored) {
            $row();
        }
        $twenty = $this->countApplicationQueries(fn () => $this->get('/diary/comment/history')
            ->assertInertia(fn ($page) => $page->has('diaries.data', 20)));

        $this->assertSame($two, $twenty);
    }
}
