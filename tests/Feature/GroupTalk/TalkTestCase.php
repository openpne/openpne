<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unit is set explicitly so a suite reads as independent of whatever an install default happens
 * to be, and so switching it off inside a test is visibly the exception.
 */
abstract class TalkTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        // Talk renders Inertia whatever the site's surface, but the shared Modern props the page
        // asserts on come from a Modern session.
        config(['openpne.surface_mode' => 'modern_default']);
    }

    protected function group(TopicReadAccess $read = TopicReadAccess::Everyone): Group
    {
        return Group::factory()->create(['topic_read_access' => $read]);
    }

    protected function memberOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    protected function adminOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    protected function subAdminOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }
}
