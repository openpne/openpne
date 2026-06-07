<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicCommentThread;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicCommentThreadTest extends TestCase
{
    use RefreshDatabase;

    private function topicWithComments(int $count): CommunityTopic
    {
        $topic = CommunityTopic::factory()->create();
        for ($number = 1; $number <= $count; $number++) {
            CommunityTopicComment::factory()->create([
                'community_topic_id' => $topic->getKey(),
                'number' => $number,
            ]);
        }

        return $topic;
    }

    public function test_default_descending_shows_the_newest_page_listed_oldest_first(): void
    {
        $topic = $this->topicWithComments(25);

        $thread = CommunityTopicCommentThread::paginate($topic);

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

        $thread = CommunityTopicCommentThread::paginate($topic, order: 'asc');

        $this->assertSame(1, $thread->firstNumber());
        $this->assertSame(20, $thread->lastNumber());
        $this->assertFalse($thread->hasOlder());
        $this->assertTrue($thread->hasNewer());
        $this->assertSame(2, $thread->newerPage());
    }

    public function test_a_short_thread_has_no_pages(): void
    {
        $topic = $this->topicWithComments(3);

        $thread = CommunityTopicCommentThread::paginate($topic);

        $this->assertFalse($thread->hasPages());
        $this->assertSame(3, $thread->total);
        $this->assertSame([1, 2, 3], $thread->comments->pluck('number')->all());
    }
}
