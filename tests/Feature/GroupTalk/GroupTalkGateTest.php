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
 * Deliberately does not extend TalkTestCase, which sets the flag explicitly: these tests are about
 * what an install resolves to on its own.
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

    public function test_a_fresh_install_runs_group_talk(): void
    {
        $this->assertDatabaseMissing('sns_settings', ['key' => 'feature_group_talk_enabled']);
        $this->assertTrue(Feature::GroupTalk->enabled());
    }

    public function test_every_talk_route_answers_404_once_an_operator_switches_the_unit_off(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $id = $group->getKey();

        $this->actingAs($member)->get("/groups/{$id}/talk")->assertOk();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->actingAs($member)->get("/groups/{$id}/talk")->assertNotFound();
        $this->actingAs($member)->getJson("/groups/{$id}/talk/messages")->assertNotFound();
        $this->actingAs($member)->postJson("/groups/{$id}/talk", ['body' => 'hello'])->assertNotFound();
        $this->actingAs($member)->post("/groups/{$id}/talk/messages/1/delete")->assertNotFound();
        // And the legacy community-timeline URLs go with it: they redirect into talk, so the unit
        // that owns the destination is the one that answers for them.
        $this->actingAs($member)->get("/community/{$id}/timeline")->assertNotFound();
    }

    public function test_switching_groups_off_closes_talk_with_it(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")->assertOk();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")->assertNotFound();
    }
}
