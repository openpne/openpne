<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Body-format authoring on the shared topic compose form (community-topic/_fields): a markdown topic
 * persists its format; an op3 topic offers no format control (the edit preserves it).
 */
class TopicFormatAuthoringTest extends TestCase
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

        $this->actingAs($member)->post(route('communityTopic.store', $community), [
            'name' => 'MD topic',
            'body' => '**bold**',
            'format' => 'markdown',
        ]);

        $this->assertDatabaseHas('community_topics', ['name' => 'MD topic', 'format' => BodyFormat::Markdown->value]);
    }

    public function test_edit_of_an_op3_topic_shows_the_note_and_no_format_input(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        $this->actingAs($author)->get(route('communityTopic.edit', $topic))
            ->assertOk()
            ->assertSee('OpenPNE 3')
            ->assertDontSee('name="format"', false);
    }
}
