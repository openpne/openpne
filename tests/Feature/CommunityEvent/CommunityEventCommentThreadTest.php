<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventCommentThread;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventCommentThreadTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithComments(int $count): CommunityEvent
    {
        $event = CommunityEvent::factory()->create();
        for ($number = 1; $number <= $count; $number++) {
            CommunityEventComment::factory()->create([
                'community_event_id' => $event->getKey(),
                'number' => $number,
            ]);
        }

        return $event;
    }

    public function test_default_descending_shows_the_newest_page_listed_oldest_first(): void
    {
        $event = $this->eventWithComments(25);

        $thread = CommunityEventCommentThread::paginate($event);

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

        $thread = CommunityEventCommentThread::paginate($event, order: 'asc');

        $this->assertSame(1, $thread->firstNumber());
        $this->assertSame(20, $thread->lastNumber());
        $this->assertFalse($thread->hasOlder());
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(2, $thread->newerPage());
    }

    public function test_ordering_follows_id_not_the_racy_number_label(): void
    {
        // Migrated data can carry numbers out of order; OpenPNE 3 pages by id (insertion order).
        $event = CommunityEvent::factory()->create();
        foreach ([99, 1, 50] as $number) {
            CommunityEventComment::factory()->create([
                'community_event_id' => $event->getKey(),
                'number' => $number,
            ]);
        }

        $thread = CommunityEventCommentThread::paginate($event, order: 'asc');

        // Insertion (id) order, not the numeric labels sorted.
        $this->assertSame([99, 1, 50], $thread->comments->pluck('number')->all());
    }

    public function test_a_short_thread_has_no_pages(): void
    {
        $event = $this->eventWithComments(3);

        $thread = CommunityEventCommentThread::paginate($event);

        $this->assertFalse($thread->hasPages());
        $this->assertSame(3, $thread->total);
        $this->assertSame([1, 2, 3], $thread->comments->pluck('number')->all());
    }
}
