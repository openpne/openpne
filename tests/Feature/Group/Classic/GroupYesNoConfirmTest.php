<?php

namespace Tests\Feature\Group\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's yesNo parts answered "no" with a second form (`_partsYesNo.php` no_url / no_method),
 * not a link: the community delete confirm sent it back to the same page, the drop and demote
 * confirms to the roster by GET.
 */
class GroupYesNoConfirmTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_delete_confirm_answers_no_with_a_get_form_to_the_home(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);

        $response = $this->actingAs($admin)->get(route('group.delete.show', $group));

        $response->assertOk();
        $response->assertSee('id="deleteConfirmForm"', false);
        $response->assertSeeInOrder([
            '<form method="POST" action="'.e(route('group.delete', $group)).'">',
            'value="Yes"',
            '<form method="get" action="'.e(route('group.show', $group)).'">',
            'value="No"',
        ], false);
        $response->assertDontSee('>Cancel<', false);
    }

    public function test_drop_and_demote_confirms_answer_no_with_a_get_form_to_the_roster(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);
        $sub = $this->joined($group, GroupRole::SubAdmin);
        $manage = e(route('group.members.manage', $group));

        foreach ([
            ['group.members.drop.show', $member, 'dropMemberConfirmForm'],
            ['group.members.demote.show', $sub, 'removeSubAdminConfirmForm'],
        ] as [$route, $target, $boxId]) {
            $response = $this->actingAs($admin)->get(route($route, ['group' => $group->getKey(), 'member_id' => $target->getKey()]));

            $response->assertOk();
            $response->assertSee('id="'.$boxId.'"', false);
            $response->assertSeeInOrder(['value="Yes"', '<form method="get" action="'.$manage.'">', 'value="No"'], false);
            $response->assertDontSee('>Cancel<', false);
        }
    }
}
