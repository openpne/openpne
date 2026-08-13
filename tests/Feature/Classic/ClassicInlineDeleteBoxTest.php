<?php

namespace Tests\Feature\Classic;

use App\Features\Group\GroupRole;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 puts the delete entry inside the edit screen of a diary, community, topic and event —
 * a box below the form whose GET button opens the delete confirmation. Labels render in English
 * here, as everywhere in the Classic suite.
 */
class ClassicInlineDeleteBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_diary_editor_carries_the_delete_box(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get(route('diary.edit', $diary))->assertOk();

        $response->assertSee('<div class="dparts box" id="formDiaryDelete">', false);
        $response->assertSee('<h3>Delete this diary</h3>', false);
        $response->assertSee('<form method="GET" action="'.route('diary.delete.show', $diary).'">', false);
    }

    public function test_the_community_editor_carries_the_delete_box_for_the_administrator(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);

        $response = $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]))->assertOk();

        $response->assertSee('<div class="dparts buttonBox" id="deleteForm">', false);
        $response->assertSee('<h3>Delete this group</h3>', false);
        $response->assertSee('Tell its members in advance');
        $response->assertSee('<form method="GET" action="'.route('group.delete.show', $group).'">', false);
    }

    public function test_the_sub_administrator_may_edit_the_community_but_not_delete_it(): void
    {
        $group = Group::factory()->create();
        $this->joined($group, GroupRole::Admin);
        $subAdmin = $this->joined($group, GroupRole::SubAdmin);

        $this->actingAs($subAdmin)->get(route('group.edit', ['id' => $group->getKey()]))
            ->assertOk()
            ->assertDontSee('id="deleteForm"', false)
            ->assertDontSee(route('group.delete.show', $group), false);
    }

    public function test_the_create_form_has_no_delete_box(): void
    {
        $this->actingAs(Member::factory()->create())->get(route('group.edit'))
            ->assertOk()
            ->assertDontSee('id="deleteForm"', false);
    }

    public function test_the_topic_editor_carries_the_delete_box(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $response = $this->actingAs($author)->get(route('group.topics.edit', $topic))->assertOk();

        $response->assertSee('<div class="dparts buttonBox" id="toDelete">', false);
        $response->assertSee('<h3>Delete the topic and comments</h3>', false);
        $response->assertSee('<form method="GET" action="'.route('group.topics.delete.show', $topic).'">', false);
    }

    public function test_the_event_editor_carries_the_delete_box(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $response = $this->actingAs($author)->get(route('group.events.edit', $event))->assertOk();

        $response->assertSee('<div class="dparts buttonBox" id="toDelete">', false);
        $response->assertSee('<h3>Delete the event and comments</h3>', false);
        $response->assertSee('<form method="GET" action="'.route('group.events.delete.show', $event).'">', false);
    }

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
}
