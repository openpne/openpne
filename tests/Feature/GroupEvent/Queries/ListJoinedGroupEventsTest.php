<?php

namespace Tests\Feature\GroupEvent\Queries;

use App\Features\GroupEvent\Queries\ListJoinedGroupEvents;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListJoinedGroupEventsTest extends TestCase
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
    public function test_lists_events_from_joined_groups_whatever_their_read_access(): void
    {
        $viewer = Member::factory()->create();
        $joined = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $open = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->join($viewer, $joined);
        $mine = GroupEvent::factory()->create(['group_id' => $joined->getKey()]);
        GroupEvent::factory()->create(['group_id' => $open->getKey()]);

        $page = (new ListJoinedGroupEvents)($viewer);

        $this->assertSame([$mine->getKey()], $page->getCollection()->modelKeys());
    }

    public function test_orders_by_bumped_at_desc_and_pages_at_fifty(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        $this->join($viewer, $group);
        foreach (range(1, 51) as $i) {
            $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => "E{$i}"]);
            DB::table('group_events')->where('id', $event->getKey())->update(['bumped_at' => now()->subMinutes(100 - $i)]);
        }
        // The oldest row gets the newest activity: a comment lifts a thread above younger ones.
        DB::table('group_events')->where('name', 'E1')->update(['bumped_at' => now()]);

        $page = (new ListJoinedGroupEvents)($viewer);

        $this->assertSame(51, $page->total());
        $this->assertCount(50, $page);
        $this->assertSame(['E1', 'E51'], $page->take(2)->pluck('name')->all());
        $this->assertSame('E3', $page->last()->name);
    }

    public function test_loads_the_comment_count_and_the_group(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create(['name' => 'Named']);
        $this->join($viewer, $group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        GroupEventComment::factory()->count(2)->create(['group_event_id' => $event->getKey()]);

        $row = (new ListJoinedGroupEvents)($viewer)->first();

        $this->assertSame(2, (int) $row->comments_count);
        $this->assertTrue($row->relationLoaded('group'));
        $this->assertSame('Named', $row->group->name);
    }
}
