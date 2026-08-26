<?php

namespace Tests\Feature\Group\Classic;

use App\Features\Group\GroupRole;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The community management screens as OpenPNE 3 drew them: radio lists on the edit form, an
 * error box (not a redirect) when joining or leaving makes no sense, the nominee's photo and
 * nickname rows on the appoint / take-over confirms, and the home sidemenu's bare photo and
 * friend-counted member names.
 */
class GroupAdminScreensParityTest extends TestCase
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

    public function test_the_edit_form_draws_the_register_policy_and_join_mail_as_radio_lists(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);

        $response = $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul class="radio_list">',
            'name="register_policy" value="open" id="community_config_register_policy_open" class="input_radio"',
            '<label for="community_config_register_policy_open">',
            'name="register_policy" value="approval" id="community_config_register_policy_close" class="input_radio"',
            'name="topic_read_access" value="members_only" id="community_config_public_flag_auth_commu_member" class="input_radio"',
            'name="topic_post_authority" value="admins_only" id="community_config_topic_authority_admin_only" class="input_radio"',
            '<th>Receive a notice mail when member joined</th>',
            'name="is_join_notification_enabled" value="1" id="community_config_is_send_pc_joinCommunity_mail_1" class="input_radio" checked',
            '<label for="community_config_is_send_pc_joinCommunity_mail_1">Receive</label>',
            'name="is_join_notification_enabled" value="0" id="community_config_is_send_pc_joinCommunity_mail_0" class="input_radio"',
            '<div class="help">',
        ], false);
        $response->assertDontSee('<select name="register_policy">', false);
    }

    public function test_joining_again_or_while_pending_lands_on_the_error_box(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert(['group_id' => $group->getKey(), 'member_id' => $applicant->getKey(), 'created_at' => now()]);

        foreach ([[$member, 'You are already joined to this'], [$applicant, 'You have already sent the participation request to this']] as [$viewer, $text]) {
            $response = $this->actingAs($viewer)->get(route('group.join.show', $group));

            $response->assertOk();
            $response->assertSee('id="page_community_join"', false);
            $response->assertSeeInOrder(['class="dparts box" id="error"', '<h3>Errors</h3>', '<div class="body">', $text, 'id="backLink"'], false);
        }
    }

    public function test_leaving_as_the_administrator_or_as_a_stranger_lands_on_the_error_box(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $stranger = Member::factory()->create();

        foreach ([[$admin, "The administrator doesn't leave the"], [$stranger, "You haven't joined this"]] as [$viewer, $text]) {
            $response = $this->actingAs($viewer)->get(route('group.quit.show', $group));

            $response->assertOk();
            $response->assertSee('id="page_community_quit"', false);
            $response->assertSeeInOrder(['class="dparts box" id="error"', $text, 'id="backLink"'], false);
        }
    }

    public function test_appoint_and_take_over_confirms_open_with_the_nominee_rows(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group);
        $profile = e(route('member.profile.show', $member));

        foreach (['group.members.appoint.show', 'group.members.transfer.show'] as $route) {
            $response = $this->actingAs($admin)->get(route($route, ['group' => $group->getKey(), 'member_id' => $member->getKey()]));

            $response->assertOk();
            $response->assertSeeInOrder([
                '<form method="POST"',
                '<table>',
                '<th>Photo</th>', '<td><a href="'.$profile.'">',
                '<th>Nickname</th>', '<td><a href="'.$profile.'">'.e($member->name).'</a></td>',
                '</table>',
                'class="operation"',
            ], false);
        }
    }

    public function test_the_home_sidemenu_draws_the_photo_bare_and_counts_friends_in_the_member_names(): void
    {
        $group = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        $member = $this->joined($group);
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $member->getKey(), 'friend_id' => $friend->getKey(), 'created_at' => now()],
            ['member_id' => $friend->getKey(), 'friend_id' => $member->getKey(), 'created_at' => now()],
        ]);

        $response = $this->actingAs($member)->get(route('group.show', $group));

        $response->assertOk();
        $content = (string) $response->getContent();
        // The photo itself, straight under p.photo — no link around it.
        $this->assertMatchesRegularExpression('/<p class="photo">\s*<img /', $content);
        $this->assertSame(1, preg_match('/id="communityImageBox".*?<p class="text">/s', $content, $box));
        $this->assertStringNotContainsString('<a ', $box[0]);
        $response->assertSee('>'.e($member->name).' (1)</a>', false);

        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);
        $this->actingAs($member)->get(route('group.show', $group))
            ->assertSee('>'.e($member->name).'</a>', false)
            ->assertDontSee(e($member->name).' (1)', false);
    }
}
