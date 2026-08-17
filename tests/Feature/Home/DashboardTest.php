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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /** A group the viewer has joined, with one message said in it at the given time. */
    private function talkedIn(Member $viewer, string $at): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => Member::factory()->create()->getKey(),
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);

        return $group;
    }

    public function test_the_talk_digest_leads_with_the_most_recently_talked_in_rooms(): void
    {
        $viewer = Member::factory()->create();
        // Six rooms created in ascending talk order, so the group id — what the membership grid
        // sorts by — is the wrong answer for both the cut and the order.
        $rooms = array_map(
            fn (int $minute): Group => $this->talkedIn($viewer, "2026-08-14 10:0{$minute}:00"),
            range(1, 6),
        );

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('talkRooms', 5)
                ->where('talkRooms.0.id', $rooms[5]->getKey()) // last talked in
                ->where('talkRooms.4.id', $rooms[1]->getKey()) // the oldest of the five that fit
            );
    }

    public function test_a_member_of_nothing_gets_an_empty_talk_digest(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('talkRooms', []));
    }

    public function test_the_talk_digest_empties_and_reads_no_message_when_the_unit_is_off(): void
    {
        $viewer = Member::factory()->create();
        $this->talkedIn($viewer, '2026-08-14 10:00:00');

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('talkRooms', []));
        $messageQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'group_messages'));
        DB::disableQueryLog();

        $this->assertSame([], $messageQueries, 'a switched-off unit still read its table');
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

        // Four distinct joined groups, each with an image and one rostered event, plus a fifth
        // image-bearing community with a topic: the activity digest reads each row's participant
        // count AND its community image, so dropping the events feeder's community.image eager load
        // lazy-loads one query per distinct community (+4), rather than hiding as +1 behind slack.
        foreach (range(1, 4) as $ignored) {
            $group = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
            GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);
            $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
            $event->participants()->attach(Member::factory()->create()->getKey());
        }
        $topicGroup = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $topicGroup->getKey(), 'member_id' => $viewer->getKey()]);
        GroupTopic::factory()->create(['group_id' => $topicGroup->getKey()]);

        // A message in each of those groups, every one by a distinct author: the talk digest is five
        // rooms, so its image, body and author loads must each stay a single batched query.
        foreach (GroupMember::where('member_id', $viewer->getKey())->pluck('group_id') as $joinedId) {
            GroupMessage::factory()->create([
                'group_id' => $joinedId,
                'member_id' => Member::factory()->create()->getKey(),
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded by the number of feeds + their eager loads, not by the number of rows: every digest
        // eager-loads its avatars, counts, and images, so adding rows must not add queries. Kept tight
        // (steady state 34 — the look resolver reads `member_preferences` only once a site offers a
        // second look, which this fixture does not) so dropping any single eager load trips it
        // instead of hiding under a loose ceiling — the events feeder's community.image turns one
        // batched fetch into four per-community lazy loads, and either of the talk digest's two
        // (image, author) turns one into five.
        $this->assertLessThan(35, $queries, "dashboard ran {$queries} queries — a per-row avatar/count/image is likely lazy-loading");
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
        DB::table('friend_requests')->insert(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);

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
