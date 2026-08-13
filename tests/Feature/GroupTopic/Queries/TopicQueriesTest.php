<?php

namespace Tests\Feature\GroupTopic\Queries;

use App\Features\GroupTopic\Queries\ListGroupTopics;
use App\Features\GroupTopic\Queries\RecentGroupTopics;
use App\Features\GroupTopic\Queries\ShowTopic;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopicQueriesTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, int> $hoursAgo topic key => updated_at age in hours */
    private function topicsWithAges(Group $group, array $hoursAgo): array
    {
        $topics = [];
        foreach ($hoursAgo as $label => $hours) {
            $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
            DB::table('group_topics')->where('id', $topic->getKey())->update(['updated_at' => now()->subHours($hours)]);
            $topics[$label] = $topic;
        }

        return $topics;
    }

    public function test_board_lists_most_recently_active_first_with_comment_counts(): void
    {
        $group = Group::factory()->create();
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->topicsWithAges($group, ['a' => 2, 'b' => 0, 'c' => 5]);
        GroupTopicComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_topic_id' => $b->getKey()]);
        // A topic in another community must not leak in.
        GroupTopic::factory()->create();

        $topics = (new ListGroupTopics)($group)->getCollection();

        $this->assertSame([$b->getKey(), $a->getKey(), $c->getKey()], $topics->pluck('id')->all());
        $this->assertSame(2, $topics->firstWhere('id', $b->getKey())->comments_count);
        $this->assertSame(0, $topics->firstWhere('id', $a->getKey())->comments_count);
    }

    public function test_recent_topics_are_capped_and_ordered(): void
    {
        $group = Group::factory()->create();
        // Ages 1h..7h, freshest first; the box keeps the 5 most recent.
        $topics = $this->topicsWithAges($group, [1, 2, 3, 4, 5, 6, 7]);

        $recent = (new RecentGroupTopics)($group, limit: 5);

        $expected = array_map(fn ($t) => $t->getKey(), array_slice($topics, 0, 5, true));
        $this->assertSame(array_values($expected), $recent->pluck('id')->all());
    }

    public function test_show_topic_loads_author_and_group_or_null(): void
    {
        $topic = GroupTopic::factory()->create();

        $found = (new ShowTopic)($topic->getKey());

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('member'));
        $this->assertTrue($found->relationLoaded('group'));
        $this->assertNull((new ShowTopic)($topic->getKey() + 999));
    }
}
