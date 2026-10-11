<?php

namespace Tests\Feature\Group\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3 homeSuccess.php declares the op_top slot on every visit, so #Top is drawn even with no notice in it. */
class GroupShowTopSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_community_home_draws_the_top_slot_with_no_notice_in_it(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member]);

        $response = $this->actingAs($member)->get(route('group.show', $group));

        $response->assertOk();
        $response->assertSee('<div id="Top">', false);
        $response->assertDontSee('id="informationAboutCommunity"', false);
    }

    public function test_the_member_management_roster_is_a_tbody_as_openpne3_wrote_it(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey(), 'role' => GroupRole::Admin]);

        $response = $this->actingAs($admin)->get(route('group.members.manage', $group));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/id="community_memberManage".*?<div class="item">\s*<table>\s*<tbody>\s*<tr>/s', (string) $response->getContent());
    }

    public function test_a_member_profile_draws_the_top_slot_when_the_notice_box_has_nothing_to_say(): void
    {
        $guestVisible = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);

        $response = $this->actingAs($viewer)->get(route('member.profile.show', $guestVisible));

        $response->assertOk();
        $response->assertSee('<div id="Top">', false);
        $response->assertDontSee('id="informationAboutThisIsYourProfilePage"', false);
    }
}
