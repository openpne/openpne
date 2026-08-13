<?php

namespace Tests\Feature\Home;

use App\Models\CommunityEvent;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
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
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/groups/recent')
            ->assertInertia(fn ($page) => $page
                ->component('community/recent')
                ->where('activity.0.kind', 'event')
                ->where('activity.0.participantCount', 2)
            );
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
            $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
            $event->participants()->attach(Member::factory()->create()->getKey());
        }
        $topicGroup = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $topicGroup->getKey(), 'member_id' => $viewer->getKey()]);
        GroupTopic::factory()->create(['group_id' => $topicGroup->getKey()]);

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded by the number of feeds + their eager loads, not by the number of rows: every digest
        // eager-loads its avatars, counts, and community images, so adding rows must not add queries.
        // Kept tight (steady state 27) so dropping any single digest's eager load trips it instead of
        // hiding under a loose ceiling — e.g. the events feeder's community.image, whose four distinct
        // groups turn one batched image fetch into four per-community lazy loads (+3 net → 30).
        $this->assertLessThan(30, $queries, "dashboard ran {$queries} queries — a per-row avatar/count/image is likely lazy-loading");
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
