<?php

namespace Tests\Feature\GroupTopic\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's listCommunitySuccess.php / showSuccess.php shapes: the Create button in a buttonBox
 * of its own, the board headed by its own title, the record's box headed "[community] kind" with
 * the Edit button as a GET form, and the closing line box naming the community top page.
 */
class GroupTopicBoardMarkupTest extends TestCase
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
        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'name' => 'First']);

        $response = $this->actingAs($member)->get(route('group.topics.index', $group));

        $response->assertOk();
        $response->assertSeeInOrder([
            'class="dparts buttonBox" id="communityTopicList"',
            '<form action="'.e(route('group.topics.new', $group)).'" method="get">',
            'value="Create"',
            'id="communityTopic_board"',
            '<h3>List of topics</h3>',
            'First(0)</a></dd>',
        ], false);
        $response->assertDontSee('id="linkLine"', false);
    }

    public function test_record_box_is_headed_by_the_community_and_edits_through_a_get_form(): void
    {
        $group = Group::factory()->create(['name' => 'Hikers']);
        $author = $this->joined($group);
        $record = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Plan']);

        $response = $this->actingAs($author)->get(route('group.topics.show', $record));

        $response->assertOk();
        $response->assertSeeInOrder([
            'class="dparts topicDetailBox" id="communityTopic_show"',
            '<h3>[Hikers] ',
            '<form action="'.e(route('group.topics.edit', $record)).'" method="get">',
            'value="Edit"',
        ], false);
        $response->assertDontSee(e(route('group.topics.delete.show', $record)), false);
    }

    public function test_topic_article_is_one_dl_with_title_name_and_body_blocks(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Plan', 'body' => 'Meet at nine.']);

        $response = $this->actingAs($author)->get(route('group.topics.show', $topic));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<dl>', '<dt>', '<dd>',
            '<div class="title">', '<p>Plan</p>',
            '<div class="name">', e($author->name),
            '<div class="body">', '<p class="text">', 'Meet at nine.',
            '</dl>',
        ], false);
        $response->assertDontSee('topicMeta', false);
    }
}
