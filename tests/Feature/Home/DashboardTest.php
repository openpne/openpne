<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
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
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('diaries')
                ->has('timeline', 1)
                ->has('communityActivity', 1)
                ->where('communityActivity.0.kind', 'topic')
                ->where('communityActivity.0.id', $topic->getKey())
                ->where('communityActivity.0.participantCount', null) // topics have no roster
                ->has('myDiaries', 1)
                ->missing('communities')
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
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('communityActivity.0.kind', 'event')
                ->where('communityActivity.0.id', $event->getKey())
                ->where('communityActivity.0.participantCount', 2)
            );
    }

    public function test_community_recent_carries_event_participant_counts(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $event->participants()->attach([$viewer->getKey(), Member::factory()->create()->getKey()]);

        $this->actingAs($viewer)
            ->get('/community/recent')
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

        // Joined-community events with rosters: the activity digest reads a per-event participant
        // count, so a missing withCount('participants') would lazy-load one query per event.
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        foreach (CommunityEvent::factory()->count(4)->create(['community_id' => $community->getKey()]) as $event) {
            $event->participants()->attach(Member::factory()->create()->getKey());
        }

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded by the number of feeds + their eager loads, not by the number of rows: every digest
        // eager-loads its avatars and counts, so adding rows must not add queries. Kept tight (the
        // steady state is ~22, four rows per digest) so dropping any single digest's eager load —
        // e.g. the my-diaries image count, which would lazy-load one query per row (+4) — trips it
        // instead of hiding under a loose ceiling.
        $this->assertLessThan(26, $queries, "dashboard ran {$queries} queries — a per-row avatar/count is likely lazy-loading");
    }

    public function test_announcements_are_zeroed_when_nothing_needs_attention(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements.friendRequests', 0)
                ->where('announcements.unreadMessages', 0)
                ->where('announcements.communityApprovals', [])
            );
    }

    public function test_announcements_report_pending_requests_unread_and_approvals(): void
    {
        $viewer = Member::factory()->create();
        $requester = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);

        $message = Message::factory()->create(['sender_id' => $requester->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $community->applicants()->attach(Member::factory()->create()->getKey());

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('announcements.friendRequests', 1)
                ->where('announcements.unreadMessages', 1)
                ->has('announcements.communityApprovals', 1)
                ->where('announcements.communityApprovals.0.communityId', $community->getKey())
                ->where('announcements.communityApprovals.0.count', 1)
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
                ->has('communityActivity')
                ->has('myDiaries')
            );
    }
}
