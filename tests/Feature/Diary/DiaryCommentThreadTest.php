<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\DiaryCommentThread;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** The duplicate at 5 sits on the newest-first page edge and the one at 19 on the oldest-first edge. */
    public function test_a_duplicate_number_on_a_page_edge_splits_in_insertion_order(): void
    {
        $diary = Diary::factory()->create();
        $ids = [];
        foreach ([1, 2, 3, 4, 5, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 23] as $number) {
            $ids[] = DiaryComment::factory()->for($diary)->create(['number' => $number])->id;
        }
        [$first5, $second5, $first19, $second19] = [$ids[4], $ids[5], $ids[19], $ids[20]];

        $newest = DiaryCommentThread::paginate($diary);
        $this->assertSame(array_slice($ids, 5), $newest->comments->pluck('id')->all());
        $this->assertSame($second5, $newest->comments->first()->id);
        $this->assertSame($first5, DiaryCommentThread::paginate($diary, page: 2)->comments->last()->id);

        $oldest = DiaryCommentThread::paginate($diary, order: 'asc');
        $this->assertSame(array_slice($ids, 0, 20), $oldest->comments->pluck('id')->all());
        $this->assertSame($first19, $oldest->comments->last()->id);
        $this->assertSame($second19, DiaryCommentThread::paginate($diary, order: 'asc', page: 2)->comments->first()->id);
    }

    /** An index scan on (diary_id, number) already returns a tie in id order on both engines, so only this SQL pin goes red without the clause. */
    public function test_the_page_query_orders_by_number_then_id_in_one_direction(): void
    {
        $diary = $this->diaryWithComments(3);

        DB::enableQueryLog();
        try {
            DiaryCommentThread::paginate($diary);
            DiaryCommentThread::paginate($diary, order: 'asc');
            $orders = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql) => preg_match('/from [`"]diary_comments[`"].* order by/', $sql) === 1)
                ->map(fn (string $sql) => preg_replace('/[`"]/', '', substr($sql, strpos($sql, 'order by'))))
                ->values()->all();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame([
            'order by number desc, id desc limit 20 offset 0',
            'order by number asc, id asc limit 20 offset 0',
        ], $orders);
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
