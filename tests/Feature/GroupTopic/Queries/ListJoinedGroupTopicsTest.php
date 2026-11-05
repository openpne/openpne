<?php

namespace Tests\Feature\GroupTopic\Queries;

use App\Features\GroupTopic\Queries\ListJoinedGroupTopics;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListJoinedGroupTopicsTest extends TestCase
{
    use RefreshDatabase;

    private function join(Member $member, Group $group): void
    {
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    /** Membership is the scope: a joined members-only board is in, a readable board the viewer never joined is out. */
    public function test_lists_topics_from_joined_groups_whatever_their_read_access(): void
    {
        $viewer = Member::factory()->create();
        $joined = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $open = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->join($viewer, $joined);
        $mine = GroupTopic::factory()->create(['group_id' => $joined->getKey()]);
        GroupTopic::factory()->create(['group_id' => $open->getKey()]);

        $page = (new ListJoinedGroupTopics)($viewer);

        $this->assertSame([$mine->getKey()], $page->getCollection()->modelKeys());
    }

    public function test_orders_by_bumped_at_desc_and_pages_at_fifty(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        $this->join($viewer, $group);
        foreach (range(1, 51) as $i) {
            $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => "T{$i}"]);
            DB::table('group_topics')->where('id', $topic->getKey())->update(['bumped_at' => now()->subMinutes(100 - $i)]);
        }
        // The oldest row gets the newest activity: a comment lifts a thread above younger ones.
        DB::table('group_topics')->where('name', 'T1')->update(['bumped_at' => now()]);

        $page = (new ListJoinedGroupTopics)($viewer);

        $this->assertSame(51, $page->total());
        $this->assertCount(50, $page);
        $this->assertSame(['T1', 'T51'], $page->take(2)->pluck('name')->all());
        $this->assertSame('T3', $page->last()->name);
    }

    public function test_loads_the_comment_count_and_the_group(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create(['name' => 'Named']);
        $this->join($viewer, $group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        GroupTopicComment::factory()->count(2)->create(['group_topic_id' => $topic->getKey()]);

        $row = (new ListJoinedGroupTopics)($viewer)->first();

        $this->assertSame(2, (int) $row->comments_count);
        $this->assertTrue($row->relationLoaded('group'));
        $this->assertSame('Named', $row->group->name);
    }
}
