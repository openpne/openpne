<?php

declare(strict_types=1);

namespace Tests\Feature\Modern;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A switched-off unit leaves the Inertia payload, not just the screen: every case here seeds rows
 * that DO reach the payload while the unit is on, so an assertion proves the emptying rather than
 * an empty fixture. The client-side hiding is presentation only, and is not what this pins.
 */
class ModernFeatureSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function joinedGroup(Member $member): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $group;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey(), 'created_at' => now()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey(), 'created_at' => now()],
        ]);
    }

    /** Switch a unit off mid-test: the container outlives a request here, so drop the scoped memos. */
    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    // --- Dashboard ---

    public function test_the_dashboard_drops_the_diary_digests(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create(['visibility' => Visibility::Members, 'title' => 'all-members-diary-row']);
        Diary::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Private,
            'title' => 'my-own-diary-row',
        ]);

        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey(), 'created_at' => now()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey(), 'created_at' => now()],
        ]);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Friends, 'title' => 'a-friend-diary-row']);
        DiaryComment::factory()->create(['diary_id' => Diary::firstWhere('title', 'all-members-diary-row')->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('diaries', 1)->has('myDiaries', 1)->has('friendDiaries', 1)->has('commentedDiaries', 1));

        $this->switchOff(Feature::Diary);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('diaries', [])
                ->where('friendDiaries', [])
                ->where('commentedDiaries', [])
                ->where('myDiaries', []))
            ->assertDontSee('all-members-diary-row')
            ->assertDontSee('my-own-diary-row');
    }

    public function test_the_dashboard_drops_the_timeline_feed(): void
    {
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'a-timeline-body-row',
        ]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('timeline', 1));

        $this->switchOff(Feature::Timeline);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timeline', []))
            ->assertDontSee('a-timeline-body-row');
    }

    public function test_the_dashboard_drops_the_community_activity_and_the_approval_notices(): void
    {
        $viewer = Member::factory()->create();
        $joined = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $joined->getKey(), 'name' => 'a-topic-row-name']);

        $administered = Group::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $administered->getKey(), 'member_id' => $viewer->getKey()]);
        $administered->applicants()->attach(Member::factory()->create()->getKey());

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('groupActivity', 1)
                ->has('announcements.groupApprovals', 1));

        $this->switchOff(Feature::Group);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groupActivity', [])
                ->where('announcements.groupApprovals', []))
            ->assertDontSee('a-topic-row-name');
    }

    public function test_the_dashboard_keeps_the_events_when_only_the_board_is_off(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->switchOff(Feature::GroupTopic);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('groupActivity', 1)
                ->where('groupActivity.0.kind', 'event')
                ->where('groupActivity.0.id', $event->getKey()));

        $this->actingAs($viewer)->get('/groups/recent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 1)
                ->where('activity.0.kind', 'event'));
    }

    public function test_the_dashboard_notices_follow_the_friend_and_message_units(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $other->getKey(), 'target_id' => $viewer->getKey(), 'created_at' => now()]);
        $message = DirectMessage::factory()->create(['sender_id' => $other->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('announcements.friendRequests', 1)
                ->where('announcements.unreadMessages', 1));

        $this->switchOff(Feature::Friend);
        $this->switchOff(Feature::DirectMessage);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('announcements.friendRequests', 0)
                ->where('announcements.unreadMessages', 0)
                ->where('unread.friendRequests', 0)
                ->where('unread.unreadMessages', 0));
    }

    // --- Profile digest ---

    // --- Shell + settings ---

    /** The nav's room list goes with the unit that owns talk, and with the one talk lives inside. */
    public function test_the_nav_room_list_goes_with_the_group_unit(): void
    {
        $viewer = Member::factory()->create();
        $this->joinedGroup($viewer);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('talkNavRooms.rooms', 1));

        $this->switchOff(Feature::Group);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('talkNavRooms', null));
    }

    public function test_the_member_settings_omit_the_diary_section(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('form.diary.options'));

        $this->switchOff(Feature::Diary);

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('form.diary')
                ->has('form.locale')); // the rest of the page is untouched
    }
}
