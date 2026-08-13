<?php

namespace Tests\Feature\GroupEvent;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Body-format authoring on the shared event compose form (group-event/_fields): a markdown event
 * persists its format; an op3 event offers no format control (the edit preserves it).
 */
class EventFormatAuthoringTest extends TestCase
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

        $this->actingAs($member)->post(route('group.events.store', $group), [
            'name' => 'MD event',
            'body' => '**bold**',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'area' => 'Yoyogi Park',
            'format' => 'markdown',
        ]);

        $this->assertDatabaseHas('group_events', ['name' => 'MD event', 'format' => BodyFormat::Markdown->value]);
    }

    public function test_edit_of_an_op3_event_shows_the_note_and_no_format_input(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        $this->actingAs($author)->get(route('group.events.edit', $event))
            ->assertOk()
            ->assertSee('OpenPNE 3')
            ->assertDontSee('name="format"', false);
    }
}
