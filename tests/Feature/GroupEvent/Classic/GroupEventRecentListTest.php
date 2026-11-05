<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3 communityEvent/recentlyEventList: the "More" target of the home recent-list gadget. */
class GroupEventRecentListTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $member;
    }

    public function test_lists_the_joined_groups_events_with_the_openpne3_row_shape(): void
    {
        $group = Group::factory()->create(['name' => 'JoinedGroup']);
        $viewer = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted']);
        GroupEventComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_event_id' => $event->getKey()]);
        GroupEvent::factory()->create(['group_id' => Group::factory()->create()->getKey(), 'name' => 'StrangerEvent']);

        $response = $this->actingAs($viewer)->get('/events');

        $response->assertOk()
            ->assertSee('id="page_communityEvent_recentlyEventList"', false)
            ->assertSee('class="dparts recentList"', false)
            ->assertSee('Recently Posted Group Events')
            ->assertSee('<a href="'.route('group.events.show', $event).'">Counted(2)</a> (JoinedGroup)', false)
            ->assertSee('<p class="number">1 - 1 of 1</p>', false)
            ->assertDontSee('StrangerEvent');
        $this->assertSame(2, substr_count($response->getContent(), 'class="pagerRelative"'));
    }

    public function test_an_empty_list_keeps_the_box_with_its_empty_line(): void
    {
        $viewer = $this->joined(Group::factory()->create());

        $this->actingAs($viewer)->get('/events')
            ->assertOk()
            ->assertSee('Recently Posted Group Events')
            ->assertSee('No events to show.')
            ->assertDontSee('pagerRelative');
    }

    public function test_a_modern_viewer_lands_on_the_group_activity_page(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->actingAs(Member::factory()->create())->get('/events')
            ->assertRedirect(route('group.recent'));
    }

    public function test_a_disabled_event_unit_hides_the_list(): void
    {
        $viewer = $this->joined(Group::factory()->create());
        $this->setSnsSetting(Feature::GroupEvent->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/events')->assertNotFound();
    }
}
