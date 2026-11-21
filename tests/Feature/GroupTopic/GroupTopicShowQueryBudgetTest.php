<?php

namespace Tests\Feature\GroupTopic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupTopicShowQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A sub-admin may delete every comment, so each row renders its delete control; a plain member's rows render none.
     *
     * @return iterable<string, array{string, GroupRole}>
     */
    public static function surfaces(): iterable
    {
        yield 'classic' => ['classic_default', GroupRole::Member];
        yield 'modern' => ['modern_default', GroupRole::Member];
        yield 'classic sub-admin' => ['classic_default', GroupRole::SubAdmin];
        yield 'modern sub-admin' => ['modern_default', GroupRole::SubAdmin];
    }

    /**
     * Each comment has an author of its own, so a per-comment lookup would scale with the page. The
     * comments are already examined for link cards: the first view's per-row marking is not a read.
     */
    #[DataProvider('surfaces')]
    public function test_a_page_of_comments_costs_no_query_per_comment(string $surface, GroupRole $role): void
    {
        config(['openpne.surface_mode' => $surface]);
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey(), 'role' => $role]);

        $short = $this->topicWithComments($group, 2);
        $long = $this->topicWithComments($group, 20);

        $this->actingAs($viewer);
        $forTwo = $this->applicationQueryCounts(fn () => $this->get(route('group.topics.show', $short))->assertOk());
        $forTwenty = $this->applicationQueryCounts(fn () => $this->get(route('group.topics.show', $long))->assertOk());

        $grew = array_filter($forTwenty, fn (int $count, string $sql): bool => $count > ($forTwo[$sql] ?? 0), ARRAY_FILTER_USE_BOTH);
        $this->assertSame([], $grew, 'queries that ran more often for 20 comments than for 2');
    }

    private function topicWithComments(Group $group, int $count): GroupTopic
    {
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        foreach (range(1, $count) as $number) {
            GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'number' => $number, 'link_card_synced_at' => now()]);
        }

        return $topic;
    }
}
