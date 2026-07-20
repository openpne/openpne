<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Body-format authoring on the shared event compose form (community-event/_fields): a markdown event
 * persists its format; an op3 event offers no format control (the edit preserves it).
 */
class EventFormatAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role = CommunityRole::Member): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_create_persists_the_markdown_format(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.store', $community), [
            'name' => 'MD event',
            'body' => '**bold**',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'area' => 'Yoyogi Park',
            'format' => 'markdown',
        ]);

        $this->assertDatabaseHas('community_events', ['name' => 'MD event', 'format' => BodyFormat::Markdown->value]);
    }

    public function test_edit_of_an_op3_event_shows_the_note_and_no_format_input(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        $this->actingAs($author)->get(route('communityEvent.edit', $event))
            ->assertOk()
            ->assertSee('OpenPNE 3')
            ->assertDontSee('name="format"', false);
    }
}
