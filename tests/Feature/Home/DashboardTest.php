<?php

namespace Tests\Feature\Home;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_renders_the_four_digests(): void
    {
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->create(['visibility' => Visibility::Members]);           // all-members feed
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Private]); // own
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('diaries')
                ->has('timeline', 1)
                ->has('groupActivity', 1)
                ->where('groupActivity.0.kind', 'topic')
                ->where('groupActivity.0.id', $topic->getKey())
                ->where('groupActivity.0.participantCount', null) // topics have no roster
                ->where('groupActivity.0.group.imageUrl', null) // no community image in this fixture
                ->has('myDiaries', 1)
                ->missing('groups')
            );
    }

    public function test_dashboard_timeline_digest_carries_reply_counts(): void
    {
        $viewer = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->count(2)->create([
            'member_id' => $viewer->getKey(),
            'in_reply_to_id' => $post->getKey(),
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.id', $post->getKey())
                ->where('timeline.0.replyCount', 2)
            );
    }

    public function test_dashboard_community_activity_carries_event_participant_counts(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('groupActivity.0.kind', 'event')
                ->where('groupActivity.0.id', $event->getKey())
                ->where('groupActivity.0.participantCount', 2)
            );
    }

    public function test_community_activity_carries_the_community_image(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $expected = $group->image->thumbnailUrl(120, 120, square: true);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('groupActivity.0.group.imageUrl', $expected));

        $this->actingAs($viewer)
            ->get('/groups/recent')
            ->assertInertia(fn ($page) => $page->where('activity.0.group.imageUrl', $expected));
    }

    public function test_community_recent_carries_event_participant_counts(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/groups/recent')
            ->assertInertia(fn ($page) => $page
                ->component('community/recent')
                ->where('activity.0.kind', 'event')
                ->where('activity.0.participantCount', 2)
            );
    }

    public function test_the_dashboard_carries_no_talk_digest(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->missing('talkRooms'));
    }

    public function test_the_friend_digest_holds_what_friends_let_friends_read(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $blockingFriend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->makeFriends($viewer, $blockingFriend);
        DB::table('member_blocks')->insert(['blocker_id' => $blockingFriend->getKey(), 'blocked_id' => $viewer->getKey(), 'created_at' => now()]);

        $forFriends = Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Friends]);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Private]);
        Diary::factory()->create(['member_id' => $blockingFriend->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->create(['visibility' => Visibility::Members]); // a stranger's

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('friendDiaries', 1)
                ->where('friendDiaries.0.id', $forFriends->getKey())
            );
    }

    public function test_the_friend_digest_goes_with_the_friend_unit(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Members]);

        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);
        $this->freshRequestState();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('friendDiaries', [])->has('diaries', 1));
    }

    public function test_a_pending_admin_nomination_is_a_notice(): void
    {
        $viewer = Member::factory()->create();
        $group = Group::factory()->create();
        $group->forceFill(['pending_admin_member_id' => $viewer->getKey()])->save();
        Group::factory()->create()->forceFill(['pending_admin_member_id' => Member::factory()->create()->getKey()])->save();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('announcements.adminTransfers', 1)
                ->where('announcements.adminTransfers.0', ['groupId' => $group->getKey(), 'groupName' => $group->name])
            );

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('announcements.adminTransfers', []));
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function ties(): array
    {
        return [
            'neither a friend nor a group' => [false, false, true],
            'a friend' => [true, false, false],
            'a group' => [false, true, false],
            'both' => [true, true, false],
        ];
    }

    #[DataProvider('ties')]
    public function test_a_member_with_no_friend_and_no_group_is_a_newcomer(bool $hasFriend, bool $hasGroup, bool $newcomer): void
    {
        $viewer = Member::factory()->create();
        if ($hasFriend) {
            $this->makeFriends($viewer, Member::factory()->create());
        }
        if ($hasGroup) {
            GroupMember::factory()->member()->create(['group_id' => Group::factory()->create()->getKey(), 'member_id' => $viewer->getKey()]);
        }
        // A feed with rows does not decide it: a long-standing member of a quiet site is no newcomer.
        Diary::factory()->create(['visibility' => Visibility::Members]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('newcomer', $newcomer));
    }

    public function test_a_switched_off_unit_counts_as_nothing_joined(): void
    {
        $viewer = Member::factory()->create();
        $this->makeFriends($viewer, Member::factory()->create());

        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, false);
        $this->freshRequestState();
        $this->actingAs($viewer)->get('/dashboard')->assertInertia(fn ($page) => $page->where('newcomer', true));

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();
        $this->actingAs($viewer)->get('/dashboard')->assertInertia(fn ($page) => $page->where('newcomer', false));

        $joined = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => Group::factory()->create()->getKey(), 'member_id' => $joined->getKey()]);
        $this->setSnsSetting(SnsSettingKey::FeatureFriendEnabled, true);
        $this->freshRequestState();
        $this->actingAs($joined)->get('/dashboard')->assertInertia(fn ($page) => $page->where('newcomer', true));
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey(), 'created_at' => now()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey(), 'created_at' => now()],
        ]);
    }

    public function test_each_digest_is_capped_to_the_preview_size(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->count(6)->create(['visibility' => Visibility::Members]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('dashboard')->has('diaries', 5));
    }

    public function test_carries_author_avatars_without_an_n_plus_1(): void
    {
        $viewer = Member::factory()->create();
        // Several diaries and timeline posts, each by a distinct author, so a lazy avatar load would
        // scale with the row count.
        foreach (Member::factory()->count(4)->create() as $author) {
            Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
            TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Open]);
        }

        // The viewer's own diaries, each with an image: the my-diaries digest reads a per-row
        // images_count, so a missing withCount('images') would lazy-load one query per diary.
        foreach (Diary::factory()->count(4)->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Private]) as $diary) {
            DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        }

        // Four joined groups with an image and a rostered event each, plus a fifth with a topic: a
        // dropped community.image eager load then costs four queries rather than hiding as +1.
        foreach (range(1, 4) as $ignored) {
            $group = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
            GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
            $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
            $event->participants()->attach(Member::factory()->create()->getKey());
        }
        $topicGroup = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $topicGroup->getKey(), 'member_id' => $viewer->getKey()]);
        GroupTopic::factory()->create(['group_id' => $topicGroup->getKey()]);

        // Four friends with a diary and an image each: the friend digest reads the same per-row
        // avatar and counts as the all-members one.
        foreach (Member::factory()->count(4)->create() as $friend) {
            $this->makeFriends($viewer, $friend);
            $diary = Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Friends]);
            DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        }

        $this->actingAs($viewer); // outside the count: the first authentication writes last_login_at once
        $queries = $this->countApplicationQueries(fn () => $this->get('/dashboard')->assertOk());

        // Bounded by the feeds and their eager loads, never by the row count (steady state 41).
        $this->assertLessThan(42, $queries, "dashboard ran {$queries} queries — a per-row avatar/count/image is likely lazy-loading");
    }

    public function test_announcements_are_zeroed_when_nothing_needs_attention(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements.friendRequests', 0)
                ->where('announcements.unreadMessages', 0)
                ->where('announcements.groupApprovals', [])
            );
    }

    public function test_announcements_report_pending_requests_unread_and_approvals(): void
    {
        $viewer = Member::factory()->create();
        $requester = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey(), 'created_at' => now()]);

        $message = DirectMessage::factory()->create(['sender_id' => $requester->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $group = Group::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        $group->applicants()->attach(Member::factory()->create()->getKey());

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements.friendRequests', 1)
                ->where('announcements.unreadMessages', 1)
                ->has('announcements.groupApprovals', 1)
                ->where('announcements.groupApprovals.0.groupId', $group->getKey())
                ->where('announcements.groupApprovals.0.count', 1)
            );
    }

    public function test_dashboard_renders_inertia_under_modern_only(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('diaries')
                ->has('timeline')
                ->has('groupActivity')
                ->has('myDiaries')
            );
    }
}
