<?php

namespace Tests\Feature\GroupTalk;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The activation contract: talk is dark on every install until the cutover switches it on for good.
 * Deliberately does NOT extend TalkTestCase — the point of these tests is what happens without the
 * opt-in that one performs.
 */
class GroupTalkGateTest extends TestCase
{
    use RefreshDatabase;

    private function group(): Group
    {
        return Group::factory()->create();
    }

    private function memberOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    public function test_a_fresh_install_has_group_talk_switched_off(): void
    {
        // No row at all: talk is dark by its fail-closed decode, not by seeded data.
        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_group_talk_enabled']);
        $this->assertFalse(Feature::GroupTalk->enabled());
    }

    public function test_every_talk_route_answers_404_while_the_unit_is_off(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $id = $group->getKey();

        $this->actingAs($member)->get("/groups/{$id}/talk")->assertNotFound();
        $this->actingAs($member)->getJson("/groups/{$id}/talk/messages")->assertNotFound();
        $this->actingAs($member)->postJson("/groups/{$id}/talk", ['body' => 'hello'])->assertNotFound();
        $this->actingAs($member)->post("/groups/{$id}/talk/messages/1/delete")->assertNotFound();
    }

    public function test_switching_groups_off_closes_talk_even_once_it_is_switched_on(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")->assertOk();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")->assertNotFound();
    }
}
