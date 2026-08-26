<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's form parts (`_partsForm.php`) starred every required label and printed
 * "* is required field." above the table; the hand-written event comment form printed the same
 * line above its table, its label starred by the form class instead of the parts.
 */
class GroupEventFormPartsTest extends TestCase
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
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        foreach ([route('group.events.new', $group), route('group.events.edit', $event)] as $url) {
            $response = $this->actingAs($member)->get($url);

            $response->assertOk();
            $response->assertSeeInOrder([
                self::NOTICE,
                '<table>',
                'Title <strong>*</strong>',
                'Open date <strong>*</strong>',
                'Area <strong>*</strong>',
                'Body <strong>*</strong>',
                '</table>',
                'class="operation"',
            ], false);
            $response->assertDontSee('Capacity <strong>*</strong>', false);
        }
    }

    public function test_comment_form_on_the_event_page_prints_the_notice_above_its_table_and_stars_the_label(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get(route('group.events.show', $event));

        $response->assertOk();
        $response->assertSeeInOrder(['id="communityEvent_comment_form"', self::NOTICE, '<table>', 'Comment <strong>*</strong>'], false);
    }
}
