<?php

namespace Tests\Feature\GroupEvent;

use App\Features\GroupEvent\GroupEventCommentThread;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupEventCommentThreadTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithComments(int $count): GroupEvent
    {
        $event = GroupEvent::factory()->create();
        for ($number = 1; $number <= $count; $number++) {
            GroupEventComment::factory()->create([
                'group_event_id' => $event->getKey(),
                'number' => $number,
            ]);
        }

        return $event;
    }

    public function test_default_descending_shows_the_newest_page_listed_oldest_first(): void
    {
        $event = $this->eventWithComments(25);

        $thread = GroupEventCommentThread::paginate($event);

        // Page 1 of DESC is the newest 5 (21-25 with size 20 → first page holds 6-25), listed ascending.
        $this->assertSame(2, $thread->lastPage);
        $this->assertSame(6, $thread->firstNumber());
        $this->assertSame(25, $thread->lastNumber());
        $this->assertTrue($thread->hasOlder());
        $this->assertFalse($thread->hasNewer());
        $this->assertSame(2, $thread->olderPage());
    }

    public function test_ascending_walks_from_the_first_comment(): void
    {
        $event = $this->eventWithComments(25);

        $thread = GroupEventCommentThread::paginate($event, order: 'asc');

        $this->assertSame(1, $thread->firstNumber());
        $this->assertSame(20, $thread->lastNumber());
        $this->assertFalse($thread->hasOlder());
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(2, $thread->newerPage());
    }

    public function test_ordering_follows_id_not_the_racy_number_label(): void
    {
        // Migrated data can carry numbers out of order; OpenPNE 3 pages by id (insertion order).
        $event = GroupEvent::factory()->create();
        foreach ([99, 1, 50] as $number) {
            GroupEventComment::factory()->create([
                'group_event_id' => $event->getKey(),
                'number' => $number,
            ]);
        }

        $thread = GroupEventCommentThread::paginate($event, order: 'asc');

        // Insertion (id) order, not the numeric labels sorted.
        $this->assertSame([99, 1, 50], $thread->comments->pluck('number')->all());
    }

    public function test_a_short_thread_has_no_pages(): void
    {
        $event = $this->eventWithComments(3);

        $thread = GroupEventCommentThread::paginate($event);

        $this->assertFalse($thread->hasPages());
        $this->assertSame(3, $thread->total);
        $this->assertSame([1, 2, 3], $thread->comments->pluck('number')->all());
    }
}
