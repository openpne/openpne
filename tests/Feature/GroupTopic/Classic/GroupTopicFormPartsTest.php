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
 * OpenPNE 3's form parts (`_partsForm.php`) starred every required label and printed
 * "* is required field." above the table.
 */
class GroupTopicFormPartsTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = '<strong>*</strong> is required field.';

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }

    public function test_new_and_edit_forms_star_the_required_labels_and_print_the_notice_above_the_table(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        foreach ([route('group.topics.new', $group), route('group.topics.edit', $topic)] as $url) {
            $response = $this->actingAs($member)->get($url);

            $response->assertOk();
            $response->assertSeeInOrder([
                self::NOTICE,
                '<table>',
                'Title <strong>*</strong>',
                'Body <strong>*</strong>',
                '</table>',
                'class="operation"',
            ], false);
        }
    }

    public function test_comment_form_on_the_topic_page_does_the_same(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get(route('group.topics.show', $topic));

        $response->assertOk();
        $response->assertSeeInOrder([
            'id="formCommunityTopicComment"',
            self::NOTICE,
            '<table>',
            'Comment <strong>*</strong>',
            '</table>',
            'class="operation"',
        ], false);
    }
}
