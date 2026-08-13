<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Classic screens stop offering a switched-off unit, so a member never follows a link into the
 * 404 the gate answers. The navigation and the gadgets carry their own tests (NavigationServiceTest,
 * GadgetServiceTest); this covers the blocks each page hard-codes.
 */
class ClassicFeatureSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function groupWithMember(Member $member): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $group;
    }

    public function test_the_community_home_keeps_the_calendar_block_when_only_the_board_is_off(): void
    {
        $member = Member::factory()->create();
        $group = $this->groupWithMember($member);

        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('<tr class="communityTopic">', false)
            ->assertSee('<tr class="communityEvent">', false);
    }

    public function test_the_community_home_keeps_the_board_block_when_only_the_calendar_is_off(): void
    {
        $member = Member::factory()->create();
        $group = $this->groupWithMember($member);

        $this->setSnsSetting(Feature::CommunityEvent->settingKey(), false);

        $this->actingAs($member)->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('<tr class="communityEvent">', false)
            ->assertSee('<tr class="communityTopic">', false);
    }

    public function test_the_settings_nav_omits_the_diary_row_when_diaries_are_off(): void
    {
        $member = Member::factory()->create();
        $diaryCategory = route('member.config', ['category' => 'diary']);

        $this->actingAs($member)->get(route('member.config'))->assertOk()->assertSee($diaryCategory, false);

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        $this->actingAs($member)->get(route('member.config'))
            ->assertOk()
            ->assertDontSee($diaryCategory, false)
            // The rest of the nav is untouched.
            ->assertSee(route('member.config', ['category' => 'language']), false);
    }

    public function test_the_diary_settings_url_folds_to_the_landing_when_diaries_are_off(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('member.config', ['category' => 'diary']))
            ->assertOk()->assertSee('id="diaryForm"', false);

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        // The bookmarked URL still renders the settings page, on the "pick an item" landing.
        $this->actingAs($member)->get(route('member.config', ['category' => 'diary']))
            ->assertOk()
            ->assertDontSee('id="diaryForm"', false)
            ->assertSee('id="configInformation"', false);
    }

    public function test_a_profile_withholds_the_friend_request_entry_when_friends_are_off(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $requestEntry = route('friend.link.show', ['id' => $owner->getKey()]);

        $this->actingAs($viewer)->get(route('member.profile.show', $owner))
            ->assertOk()->assertSee($requestEntry, false);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->actingAs($viewer)->get(route('member.profile.show', $owner))
            ->assertOk()
            ->assertDontSee($requestEntry, false)
            ->assertDontSee('id="informationAboutThisIsYourProfilePage"', false);
    }

    /** The own-page notice shares the box but says nothing about friends, so it survives. */
    public function test_the_own_page_notice_survives_friends_being_off(): void
    {
        $member = Member::factory()->create();

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->actingAs($member)->get(route('member.profile.show', $member))
            ->assertOk()
            ->assertSee('id="informationAboutThisIsYourProfilePage"', false)
            ->assertSee(route('member.profile.edit'), false);
    }

    public function test_the_home_landing_drops_the_link_of_each_switched_off_unit(): void
    {
        $member = Member::factory()->create();
        $diaryLink = '<li><a href="'.route('diary.list_member').'">';
        $friendLink = '<li><a href="'.route('friend.list').'">';

        $this->actingAs($member)->get('/')->assertOk()
            ->assertSee($diaryLink, false)->assertSee($friendLink, false);

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        $this->actingAs($member)->get('/')->assertOk()
            ->assertDontSee($diaryLink, false)->assertSee($friendLink, false);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->actingAs($member)->get('/')->assertOk()
            ->assertDontSee($friendLink, false)
            // The links no unit owns stay.
            ->assertSee('<li><a href="'.route('member.search').'">', false);
    }
}
