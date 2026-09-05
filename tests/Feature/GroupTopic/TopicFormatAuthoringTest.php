<?php

namespace Tests\Feature\GroupTopic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicFormatAuthoringTest extends TestCase
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

    public function test_create_persists_the_markdown_format(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.topics.store', $group), [
            'name' => 'MD topic',
            'body' => '**bold**',
            'format' => 'markdown',
        ]);

        $this->assertDatabaseHas('group_topics', ['name' => 'MD topic', 'format' => BodyFormat::Markdown->value]);
    }

    public function test_edit_of_an_op3_topic_shows_the_note_and_no_format_input(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        $this->actingAs($author)->get(route('group.topics.edit', $topic))
            ->assertOk()
            ->assertSee('OpenPNE 3')
            ->assertDontSee('name="format"', false);
    }
}
