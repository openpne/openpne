<?php

namespace Tests\Feature\GroupTopic\Classic;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3 communityTopic/recentlyTopicList: the "More" target of the home recent-list gadget. */
class GroupTopicRecentListTest extends TestCase
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

    public function test_lists_the_joined_groups_topics_with_the_openpne3_row_shape(): void
    {
        $group = Group::factory()->create(['name' => 'JoinedGroup']);
        $viewer = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted']);
        GroupTopicComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_topic_id' => $topic->getKey()]);
        GroupTopic::factory()->create(['group_id' => Group::factory()->create()->getKey(), 'name' => 'StrangerTopic']);

        $response = $this->actingAs($viewer)->get('/topics');

        $response->assertOk()
            ->assertSee('id="page_communityTopic_recentlyTopicList"', false)
            ->assertSee('class="dparts recentList"', false)
            ->assertSee('Recently Posted Group Topics')
            // recentlyTopicListSuccess.php: sprintf('%s (%s)', link 'name(count)', community name).
            ->assertSee('<a href="'.route('group.topics.show', $topic).'">Counted(2)</a> (JoinedGroup)', false)
            ->assertSee('<p class="number">1 - 1 of 1</p>', false)
            ->assertDontSee('StrangerTopic');
        $this->assertSame(2, substr_count($response->getContent(), 'class="pagerRelative"'));
    }

    public function test_an_empty_list_keeps_the_box_with_its_empty_line(): void
    {
        $viewer = $this->joined(Group::factory()->create());

        $this->actingAs($viewer)->get('/topics')
            ->assertOk()
            ->assertSee('Recently Posted Group Topics')
            ->assertSee('No topics to show.')
            ->assertDontSee('pagerRelative');
    }

    public function test_a_disabled_topic_unit_hides_the_list(): void
    {
        $viewer = $this->joined(Group::factory()->create());
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/topics')->assertNotFound();
    }
}
