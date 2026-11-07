<?php

namespace Tests\Feature\GroupEvent\Modern;

use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GroupEventRecentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.surface_mode', 'modern_only');
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $member;
    }

    public function test_lists_the_joined_groups_events_as_activity_rows(): void
    {
        $group = Group::factory()->create(['name' => 'JoinedGroup', 'file_id' => File::factory()->create()->getKey()]);
        $viewer = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted', 'open_date' => '2030-04-05']);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);
        GroupEventComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_event_id' => $event->getKey()]);
        GroupEvent::factory()->create(['group_id' => Group::factory()->create()->getKey(), 'name' => 'StrangerEvent']);

        $this->actingAs($viewer)->get('/events')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('group/event/recent')
                ->has('events.data', 1)
                ->where('events.data.0.kind', 'event')
                ->where('events.data.0.id', $event->getKey())
                ->where('events.data.0.commentCount', 2)
                // Loaded by the Modern arm alone; the serializer prints 0 for a missing count.
                ->where('events.data.0.participantCount', 2)
                ->where('events.data.0.openDate', '2030-04-05')
                ->where('events.data.0.group.imageUrl', $group->image->thumbnailUrl(120, 120, square: true))
                ->where('events.meta.perPage', 50)
                ->where('events.meta.total', 1)
            );
    }

    public function test_the_page_query_selects_the_page(): void
    {
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        GroupEvent::factory()->count(51)->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->get('/events?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events.data', 1)
                ->where('events.meta.currentPage', 2)
                ->where('events.meta.lastPage', 2)
            );
    }

    public function test_a_disabled_event_unit_hides_the_list(): void
    {
        $viewer = $this->joined(Group::factory()->create());
        $this->setSnsSetting(Feature::GroupEvent->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/events')->assertNotFound();
    }

    public function test_classic_only_keeps_the_classic_list(): void
    {
        config()->set('openpne.surface_mode', 'classic_only');
        $viewer = $this->joined(Group::factory()->create());

        $this->actingAs($viewer)->get('/events')
            ->assertOk()
            ->assertSee('class="dparts recentList"', false);
    }
}
