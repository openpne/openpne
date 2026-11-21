<?php

namespace Tests\Feature\Group;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupPlaceTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_OPEN = ['talk' => true, 'topic' => true, 'event' => true];

    private const ALL_CLOSED = ['talk' => false, 'topic' => false, 'event' => false];

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function join(Group $group, Member $member, string $role = 'member'): void
    {
        GroupMember::factory()->{$role}()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    /** @return array<string, array{string, string, string}> The screen, its path, and a unit other than its own to switch off. */
    public static function innerScreens(): array
    {
        return [
            'talk' => ['group/talk/index', '/groups/{group}/talk', 'event'],
            'topic list' => ['group/topic/index', '/groups/{group}/topics', 'talk'],
            'topic' => ['group/topic/show', '/topics/{topic}', 'talk'],
            'event list' => ['group/event/index', '/groups/{group}/events', 'talk'],
            'event' => ['group/event/show', '/events/{event}', 'topic'],
            'event participants' => ['group/event/members', '/events/{event}/members', 'talk'],
            'members' => ['community/members', '/groups/{group}/members', 'talk'],
            'pending' => ['community/pending', '/groups/{group}/members/pending', 'topic'],
            'manage' => ['community/manage', '/groups/{group}/members/manage', 'event'],
        ];
    }

    #[DataProvider('innerScreens')]
    public function test_every_screen_inside_a_group_says_which_boards_its_reader_may_open(string $component, string $path, string $other): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        $this->join($group, $admin, 'admin');
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $url = strtr($path, ['{group}' => $group->getKey(), '{topic}' => $topic->getKey(), '{event}' => $event->getKey()]);

        $this->actingAs($admin)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('group.id', $group->getKey())
                ->where('openSections', self::ALL_OPEN)
            );

        // A switched-off unit drops out of the list on every screen, not only on the group's top.
        $this->setSnsSetting(match ($other) {
            'talk' => SnsSettingKey::FeatureGroupTalkEnabled,
            'topic' => SnsSettingKey::FeatureGroupTopicEnabled,
            'event' => SnsSettingKey::FeatureGroupEventEnabled,
        }, false);

        $this->actingAs($admin)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('openSections', [$other => false] + self::ALL_OPEN));
    }

    public function test_a_stranger_to_a_members_only_group_is_offered_none_of_its_boards(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get("/groups/{$group->getKey()}/members")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('community/members')
                ->where('openSections', self::ALL_CLOSED)
            );
    }

    public function test_a_member_of_a_members_only_group_is_offered_all_of_them(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = Member::factory()->create();
        $this->join($group, $member);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/members")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('openSections', self::ALL_OPEN));
    }
}
