<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\DiaryCommentThread;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryCommentThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_order_shows_the_newest_page_listed_oldest_first(): void
    {
        $diary = $this->diaryWithComments(25);

        $thread = DiaryCommentThread::paginate($diary);

        // Default DESC, size 20: the newest 20 (numbers 6-25), reversed to ascending for display.
        $this->assertSame(range(6, 25), $thread->comments->pluck('number')->all());
        $this->assertSame(6, $thread->firstNumber());
        $this->assertSame(25, $thread->lastNumber());
        $this->assertSame(2, $thread->lastPage);
    }

    public function test_default_order_older_page_holds_the_earliest_comments(): void
    {
        $diary = $this->diaryWithComments(25);

        $thread = DiaryCommentThread::paginate($diary, page: 2);

        $this->assertSame(range(1, 5), $thread->comments->pluck('number')->all());
        // On the newest-first default, page 2 is older; "Older" is exhausted, "Newer" goes back.
        $this->assertFalse($thread->hasOlder());
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(1, $thread->newerPage());
    }

    public function test_default_order_first_page_navigates_older(): void
    {
        $thread = DiaryCommentThread::paginate($this->diaryWithComments(25));

        $this->assertTrue($thread->hasOlder());
        $this->assertFalse($thread->hasNewer());
        $this->assertSame(2, $thread->olderPage());
    }

    public function test_ascending_order_walks_from_the_first_comment(): void
    {
        $diary = $this->diaryWithComments(25);

        $thread = DiaryCommentThread::paginate($diary, order: 'asc');

        $this->assertSame(range(1, 20), $thread->comments->pluck('number')->all());
        $this->assertTrue($thread->ascending);
        $this->assertFalse($thread->hasOlder()); // page 1 ascending: nothing older
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(2, $thread->newerPage());
    }

    public function test_size_falls_back_to_the_default_for_invalid_values(): void
    {
        $diary = $this->diaryWithComments(5);

        $this->assertSame(20, DiaryCommentThread::paginate($diary, size: 50)->size);
        $this->assertSame(20, DiaryCommentThread::paginate($diary, size: 'x')->size);
        $this->assertSame(100, DiaryCommentThread::paginate($diary, size: 100)->size);
    }

    public function test_page_is_clamped_into_range(): void
    {
        $diary = $this->diaryWithComments(25);

        $this->assertSame(2, DiaryCommentThread::paginate($diary, page: 99)->page);
        $this->assertSame(1, DiaryCommentThread::paginate($diary, page: 0)->page);
    }

    public function test_a_short_thread_does_not_paginate(): void
    {
        $thread = DiaryCommentThread::paginate($this->diaryWithComments(5));

        $this->assertFalse($thread->hasPages());
        $this->assertFalse($thread->offersSizeSwitch());
        $this->assertSame(range(1, 5), $thread->comments->pluck('number')->all());
    }

    public function test_size_switch_offers_the_other_size(): void
    {
        $diary = $this->diaryWithComments(25);

        $this->assertSame([100], DiaryCommentThread::paginate($diary)->otherSizes());
        $this->assertSame([20], DiaryCommentThread::paginate($diary, size: 100)->otherSizes());
        $this->assertTrue(DiaryCommentThread::paginate($diary)->offersSizeSwitch());
    }

    public function test_link_drops_default_order_and_first_page(): void
    {
        $diary = $this->diaryWithComments(25);
        $thread = DiaryCommentThread::paginate($diary);

        $base = route('diary.show', ['diary' => $diary, 'size' => 20]);
        $this->assertSame($base, $thread->link(1, 20, false));                 // default: no order, no page
        $this->assertStringContainsString('order=asc', $thread->link(1, 20, true));
        $this->assertStringContainsString('page=2', $thread->link(2, 20, false));
    }

    private function diaryWithComments(int $count): Diary
    {
        $diary = Diary::factory()->create();
        for ($number = 1; $number <= $count; $number++) {
            DiaryComment::factory()->for($diary)->create(['number' => $number]);
        }

        return $diary;
    }
}
