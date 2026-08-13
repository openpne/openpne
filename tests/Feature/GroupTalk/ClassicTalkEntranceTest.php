<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Member;
use App\Support\SnsSettingKey;

/**
 * The Classic group page's talk link — the box that replaced the community timeline's slot. Classic
 * has no talk surface of its own (the link lands on the Modern screen), so the link IS the Classic
 * entrance, gated by the same read answer the Modern card uses.
 */
class ClassicTalkEntranceTest extends TalkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // TalkTestCase pins a Modern session for the Inertia suites; this suite reads the Classic render.
        config(['openpne.surface_mode' => 'classic_default']);
    }

    private function talkLink(int $groupId): string
    {
        return route('group.talk.show', ['group' => $groupId]);
    }

    public function test_a_member_sees_the_talk_link_in_the_old_timeline_slot(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('id="groupTalk"', false)
            ->assertSee($this->talkLink($group->getKey()), false);
    }

    public function test_an_everyone_group_shows_the_link_to_a_non_member_reader(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertSee($this->talkLink($group->getKey()), false);
    }

    public function test_a_members_only_group_hides_the_link_from_an_outsider(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);

        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee($this->talkLink($group->getKey()), false);
    }

    public function test_the_link_survives_the_board_being_switched_off(): void
    {
        // Talk is its own unit: the board's toggle must not take the entrance with it (the old
        // entrance borrowed the board's null seam and did exactly that).
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTopicEnabled, false);
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee($this->talkLink($group->getKey()), false);
    }

    public function test_the_link_disappears_with_the_talk_unit(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee($this->talkLink($group->getKey()), false);
    }
}
