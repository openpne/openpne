<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Member;
use App\Support\SnsSettingKey;

/**
 * The talk entrance on a group's top page. It asks its own two questions — the unit, and this
 * group's read gate — rather than borrowing the topic board's "may the viewer read the boards"
 * seam: the two units are switched independently, and a site running talk without the board would
 * otherwise have a readable conversation with nothing linking to it.
 */
class GroupTalkEntranceTest extends TalkTestCase
{
    public function test_a_reader_of_an_everyone_group_gets_the_entrance(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', true));
    }

    /** The regression this prop exists for: the boards are off, talk is on and readable. */
    public function test_the_entrance_survives_the_topic_board_being_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTopicEnabled, false);
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                // The board's own card is gone…
                ->where('recentTopics', null)
                // …and talk's entrance is not.
                ->where('canViewTalk', true));
    }

    public function test_a_non_member_gets_no_entrance_to_a_members_only_group(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', false));

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', true));
    }

    public function test_no_entrance_while_the_unit_is_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', false));
    }

    public function test_no_entrance_while_groups_themselves_are_switched_off(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();

        // The whole group page goes with its unit; the prop never gets the chance to be wrong.
        $this->actingAs($member)->get("/groups/{$group->getKey()}")->assertNotFound();
    }
}
