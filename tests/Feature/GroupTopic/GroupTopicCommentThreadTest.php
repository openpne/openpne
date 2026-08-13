<?php

namespace Tests\Feature\GroupTopic;

use App\Features\GroupTopic\GroupTopicCommentThread;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupTopicCommentThreadTest extends TestCase
{
    use RefreshDatabase;

    private function topicWithComments(int $count): GroupTopic
    {
        $topic = GroupTopic::factory()->create();
        for ($number = 1; $number <= $count; $number++) {
            GroupTopicComment::factory()->create([
                'group_topic_id' => $topic->getKey(),
                'number' => $number,
            ]);
        }

        return $topic;
    }

    public function test_default_descending_shows_the_newest_page_listed_oldest_first(): void
    {
        $topic = $this->topicWithComments(25);

        $thread = GroupTopicCommentThread::paginate($topic);

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
        $topic = $this->topicWithComments(25);

        $thread = GroupTopicCommentThread::paginate($topic, order: 'asc');

        $this->assertSame(1, $thread->firstNumber());
        $this->assertSame(20, $thread->lastNumber());
        $this->assertFalse($thread->hasOlder());
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(2, $thread->newerPage());
    }

    public function test_ordering_follows_id_not_the_racy_number_label(): void
    {
        // Migrated data can carry numbers out of order; OpenPNE 3 pages by id (insertion order).
        $topic = GroupTopic::factory()->create();
        foreach ([99, 1, 50] as $number) {
            GroupTopicComment::factory()->create([
                'group_topic_id' => $topic->getKey(),
                'number' => $number,
            ]);
        }

        $thread = GroupTopicCommentThread::paginate($topic, order: 'asc');

        // Insertion (id) order, not the numeric labels sorted.
        $this->assertSame([99, 1, 50], $thread->comments->pluck('number')->all());
    }

    public function test_a_short_thread_has_no_pages(): void
    {
        $topic = $this->topicWithComments(3);

        $thread = GroupTopicCommentThread::paginate($topic);

        $this->assertFalse($thread->hasPages());
        $this->assertSame(3, $thread->total);
        $this->assertSame([1, 2, 3], $thread->comments->pluck('number')->all());
    }
}
