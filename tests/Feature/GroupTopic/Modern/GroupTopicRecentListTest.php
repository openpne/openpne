<?php

namespace Tests\Feature\GroupTopic\Modern;

use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GroupTopicRecentListTest extends TestCase
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

    public function test_lists_the_joined_groups_topics_as_activity_rows(): void
    {
        $group = Group::factory()->create(['name' => 'JoinedGroup', 'file_id' => File::factory()->create()->getKey()]);
        $viewer = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted']);
        GroupTopicComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_topic_id' => $topic->getKey()]);
        // An Everyone group the viewer has not joined: readable, but not in this set (membership, as OpenPNE 3).
        GroupTopic::factory()->create(['group_id' => Group::factory()->create()->getKey(), 'name' => 'StrangerTopic']);

        $this->actingAs($viewer)->get('/topics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('group/topic/recent')
                ->has('topics.data', 1)
                ->where('topics.data.0.kind', 'topic')
                ->where('topics.data.0.id', $topic->getKey())
                ->where('topics.data.0.commentCount', 2)
                ->where('topics.data.0.participantCount', null)
                ->where('topics.data.0.openDate', null)
                ->where('topics.data.0.group.name', 'JoinedGroup')
                ->where('topics.data.0.group.imageUrl', $group->image->thumbnailUrl(120, 120, square: true))
                ->where('topics.meta.perPage', 50)
                ->where('topics.meta.total', 1)
            );
    }

    public function test_the_page_query_selects_the_page(): void
    {
        $group = Group::factory()->create();
        $viewer = $this->joined($group);
        GroupTopic::factory()->count(51)->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->get('/topics?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('topics.data', 1)
                ->where('topics.meta.currentPage', 2)
                ->where('topics.meta.lastPage', 2)
            );
    }

    public function test_a_disabled_topic_unit_hides_the_list(): void
    {
        $viewer = $this->joined(Group::factory()->create());
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/topics')->assertNotFound();
    }

    public function test_classic_only_keeps_the_classic_list(): void
    {
        config()->set('openpne.surface_mode', 'classic_only');
        $viewer = $this->joined(Group::factory()->create());

        $this->actingAs($viewer)->get('/topics')
            ->assertOk()
            ->assertSee('class="dparts recentList"', false);
    }
}
