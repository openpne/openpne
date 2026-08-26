<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's listCommunitySuccess.php / showSuccess.php shapes: the Create button in a buttonBox
 * of its own, the board headed by its own title and closed by nothing, the record's box headed
 * "[community] kind" with the Edit button as a GET form and closed by the line naming the
 * community top page.
 */
class GroupEventBoardMarkupTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_board_puts_the_create_button_in_its_own_box_and_ends_with_the_list(): void
    {
        $group = Group::factory()->create(['name' => 'Hikers']);
        $member = $this->joined($group);
        GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'name' => 'First']);

        $response = $this->actingAs($member)->get(route('group.events.index', $group));

        $response->assertOk();
        $response->assertSeeInOrder([
            'class="dparts buttonBox" id="communityEventList"',
            '<form action="'.e(route('group.events.new', $group)).'" method="get">',
            'value="Create"',
            'id="communityEvent_board"',
            '<h3>List of events</h3>',
            'First(0)</a></dd>',
        ], false);
        $response->assertDontSee('id="linkLine"', false);
    }

    public function test_record_box_is_headed_by_the_community_and_edits_through_a_get_form(): void
    {
        $group = Group::factory()->create(['name' => 'Hikers']);
        $author = $this->joined($group);
        $record = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Plan']);

        $response = $this->actingAs($author)->get(route('group.events.show', $record));

        $response->assertOk();
        $response->assertSeeInOrder([
            'class="dparts listBox" id="communityEvent"',
            '<h3>[Hikers] ',
            '<form action="'.e(route('group.events.edit', $record)).'" method="get">',
            'value="Edit"',
        ], false);
        $response->assertDontSee(e(route('group.events.delete.show', $record)), false);
    }

    public function test_event_table_carries_the_name_and_body_rows(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Picnic', 'body' => 'Bring lunch.']);

        $response = $this->actingAs($author)->get(route('group.events.show', $event));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<th>Writer</th>', '<th>Name</th>', '<td>Picnic</td>',
            '<th>Open date</th>', '<th>Area</th>',
            '<th>Body</th>', 'Bring lunch.',
            '<th>Application deadline</th>', '<th>Capacity</th>', '<th>Count of Member</th>',
            '</table>',
        ], false);
        $response->assertDontSee('eventBody', false);
    }
}
