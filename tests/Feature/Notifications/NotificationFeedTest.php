<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_own_notifications_newest_first_with_hydrated_actors(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $older = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()], createdAt: now()->subHour());
        $newer = $this->seedRow($viewer, 'direct_message_received', ['sender_id' => $actor->getKey(), 'direct_message_id' => 12], createdAt: now());

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('notifications/index')
                ->where('feed.data.0.id', $newer->getKey())
                ->where('feed.data.0.kind', 'direct_message_received')
                // The sentence ships resolved; the client prints it rather than holding its own table.
                ->where('feed.data.0.label', __(':name sent you a message.', ['name' => $actor->name]))
                ->where('feed.data.0.read', false)
                ->where('feed.data.0.actor.name', $actor->name)
                ->where('feed.data.1.id', $older->getKey())
                ->where('feed.meta.total', 2),
            );
    }

    public function test_feed_does_not_show_another_members_notifications(): void
    {
        [$viewer, $other, $actor] = Member::factory()->count(3)->create()->all();
        $this->seedRow($other, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('feed.meta.total', 0));
    }

    public function test_withdrawn_actor_degrades_to_a_null_actor(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $actor->delete();

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.data.0.actor', null)
                ->where('feed.data.0.label', __(':name sent you a %friend% request.', ['name' => __('Withdrawn member')])),
            );
    }

    public function test_open_marks_the_row_read_and_redirects_to_its_target(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $message = DirectMessage::factory()->create(['sender_id' => $actor->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);
        $row = $this->seedRow($viewer, 'direct_message_received', ['sender_id' => $actor->getKey(), 'direct_message_id' => $message->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/message/read/'.$message->getKey());

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_open_falls_back_to_the_feed_when_the_message_left_the_inbox(): void
    {
        // No live inbox receipt (trashed/purged/never delivered) — the read page would 404, so
        // the row is marked read and the member lands back on the feed instead.
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'direct_message_received', ['sender_id' => $actor->getKey(), 'direct_message_id' => 34]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_open_redirects_a_friend_request_to_the_requests_page(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/friend/requests');
    }

    public function test_open_falls_back_to_the_feed_when_the_target_is_gone(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_request_accepted', ['accepter_id' => $actor->getKey()]);
        $actor->delete();

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_open_redirects_a_diary_comment_to_the_diary(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $viewer->getKey()]);
        $row = $this->seedRow($viewer, 'diary_commented', ['commenter_id' => $actor->getKey(), 'diary_id' => $diary->getKey(), 'reason' => 'reply']);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/diary/'.$diary->getKey());
    }

    public function test_open_redirects_a_diary_post_to_the_diary(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $row = $this->seedRow($viewer, 'diary_posted', ['author_id' => $author->getKey(), 'diary_id' => $diary->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/diary/'.$diary->getKey());
    }

    public function test_open_falls_back_to_the_feed_when_the_diary_is_gone_or_hidden(): void
    {
        [$viewer, $actor, $owner] = Member::factory()->count(3)->create()->all();
        // A private diary the viewer once commented on but can no longer view.
        $hidden = Diary::factory()->private()->create(['member_id' => $owner->getKey()]);
        $gone = $this->seedRow($viewer, 'diary_commented', ['commenter_id' => $actor->getKey(), 'diary_id' => 424242, 'reason' => 'related']);
        $unviewable = $this->seedRow($viewer, 'diary_commented', ['commenter_id' => $actor->getKey(), 'diary_id' => $hidden->getKey(), 'reason' => 'related']);

        $this->actingAs($viewer)->post("/notifications/{$gone->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
        $this->actingAs($viewer)->post("/notifications/{$unviewable->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_open_redirects_a_mention_to_the_thread_root(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $root = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        // The row addresses the reply that named the viewer; a thread has one address.
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $author->getKey()]);
        $row = $this->seedRow($viewer, 'timeline_mentioned', ['author_id' => $author->getKey(), 'post_id' => $reply->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/timeline/'.$root->getKey());
    }

    public function test_open_redirects_a_new_post_row_to_the_post(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $row = $this->seedRow($viewer, 'timeline_posted', ['author_id' => $author->getKey(), 'post_id' => $post->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/timeline/'.$post->getKey());
    }

    public function test_open_redirects_a_reply_row_to_the_thread_root(): void
    {
        [$viewer, $replier] = Member::factory()->count(2)->create()->all();
        $root = TimelinePost::factory()->create(['member_id' => $viewer->getKey()]);
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $replier->getKey()]);
        $row = $this->seedRow($viewer, 'timeline_replied', [
            'replier_id' => $replier->getKey(), 'post_id' => $reply->getKey(), 'reason' => 'reply',
        ]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/timeline/'.$root->getKey());
    }

    public function test_a_reply_row_hydrates_the_replier_as_its_actor(): void
    {
        [$viewer, $replier] = Member::factory()->count(2)->create()->all();
        $root = TimelinePost::factory()->create(['member_id' => $viewer->getKey()]);
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $replier->getKey()]);
        $this->seedRow($viewer, 'timeline_replied', [
            'replier_id' => $replier->getKey(), 'post_id' => $reply->getKey(), 'reason' => 'related',
        ]);

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.data.0.kind', 'timeline_replied')
                ->where('feed.data.0.reason', 'related')
                ->where('feed.data.0.actor.name', $replier->name)
                ->where('feed.data.0.label', __(':name commented on a %activity% post you commented on.', ['name' => $replier->name])),
            );
    }

    public function test_open_falls_back_to_the_feed_when_a_broadcast_row_is_no_longer_viewable(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $hidden = TimelinePost::factory()->private()->create(['member_id' => $author->getKey()]);
        $row = $this->seedRow($viewer, 'timeline_posted', ['author_id' => $author->getKey(), 'post_id' => $hidden->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_open_falls_back_to_the_feed_when_the_mentioning_post_is_gone_or_hidden(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $hidden = TimelinePost::factory()->private()->create(['member_id' => $author->getKey()]);
        $gone = $this->seedRow($viewer, 'timeline_mentioned', ['author_id' => $author->getKey(), 'post_id' => 424242]);
        $unviewable = $this->seedRow($viewer, 'timeline_mentioned', ['author_id' => $author->getKey(), 'post_id' => $hidden->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$gone->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
        $this->actingAs($viewer)->post("/notifications/{$unviewable->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_open_redirects_a_topic_comment_to_the_topic(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $topic = CommunityTopic::factory()->create(['member_id' => $viewer->getKey()]);
        $row = $this->seedRow($viewer, 'community_topic_commented', ['commenter_id' => $actor->getKey(), 'topic_id' => $topic->getKey(), 'reason' => 'reply']);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/communityTopic/'.$topic->getKey());
    }

    public function test_open_redirects_a_new_topic_and_event_to_their_pages(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        $topic = CommunityTopic::factory()->create(['member_id' => $author->getKey()]);
        $event = CommunityEvent::factory()->create(['member_id' => $author->getKey()]);
        $topicRow = $this->seedRow($viewer, 'community_topic_posted', ['author_id' => $author->getKey(), 'topic_id' => $topic->getKey()]);
        $eventRow = $this->seedRow($viewer, 'community_event_posted', ['author_id' => $author->getKey(), 'event_id' => $event->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$topicRow->getKey()}/open")
            ->assertRedirect('/communityTopic/'.$topic->getKey());
        $this->actingAs($viewer)->post("/notifications/{$eventRow->getKey()}/open")
            ->assertRedirect('/communityEvent/'.$event->getKey());
    }

    public function test_open_falls_back_to_the_feed_when_the_board_is_gone_or_unreadable(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        // A members-only board the viewer is not a member of: the topic exists but is unreadable.
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $hidden = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $gone = $this->seedRow($viewer, 'community_event_commented', ['commenter_id' => $actor->getKey(), 'event_id' => 424242, 'reason' => 'reply']);
        $unreadable = $this->seedRow($viewer, 'community_topic_commented', ['commenter_id' => $actor->getKey(), 'topic_id' => $hidden->getKey(), 'reason' => 'related']);

        $this->actingAs($viewer)->post("/notifications/{$gone->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
        $this->actingAs($viewer)->post("/notifications/{$unreadable->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_open_redirects_a_community_join_to_the_community(): void
    {
        [$viewer, $joiner] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create();
        $row = $this->seedRow($viewer, 'community_joined', ['new_member_id' => $joiner->getKey(), 'community_id' => $community->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect('/community/'.$community->getKey());
    }

    public function test_open_falls_back_to_the_feed_when_the_community_is_gone(): void
    {
        [$viewer, $joiner] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'community_joined', ['new_member_id' => $joiner->getKey(), 'community_id' => 424242]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_admin_transfer_and_sub_admin_kinds_hydrate_their_actor_and_link_to_the_community(): void
    {
        [$viewer, $requester, $appointer] = Member::factory()->count(3)->create()->all();
        $community = Community::factory()->create();
        $appointed = $this->seedRow($viewer, 'community_sub_admin_appointed', ['appointer_id' => $appointer->getKey(), 'community_id' => $community->getKey()], createdAt: now()->subMinute());
        $transfer = $this->seedRow($viewer, 'community_admin_transfer_requested', ['requester_id' => $requester->getKey(), 'community_id' => $community->getKey()], createdAt: now());

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.data.0.kind', 'community_admin_transfer_requested')
                ->where('feed.data.0.actor.name', $requester->name)
                ->where('feed.data.1.kind', 'community_sub_admin_appointed')
                ->where('feed.data.1.actor.name', $appointer->name),
            );

        $this->actingAs($viewer)->post("/notifications/{$transfer->getKey()}/open")
            ->assertRedirect('/community/'.$community->getKey());
        $this->actingAs($viewer)->post("/notifications/{$appointed->getKey()}/open")
            ->assertRedirect('/community/'.$community->getKey());
    }

    public function test_admin_transfer_falls_back_to_the_feed_when_the_community_is_gone(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'community_admin_transfer_requested', ['requester_id' => $requester->getKey(), 'community_id' => 424242]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_open_rejects_another_members_notification(): void
    {
        [$viewer, $other, $actor] = Member::factory()->count(3)->create()->all();
        $row = $this->seedRow($other, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")->assertNotFound();

        $this->assertNull($row->fresh()->read_at);
    }

    public function test_read_all_marks_only_own_unread_rows(): void
    {
        [$viewer, $other, $actor] = Member::factory()->count(3)->create()->all();
        $mine = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $theirs = $this->seedRow($other, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->from('/notifications')->post('/notifications/read-all')
            ->assertRedirect('/notifications');

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_unread_shared_prop_counts_unread_feed_rows(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $this->seedRow($viewer, 'direct_message_received', ['sender_id' => $actor->getKey(), 'direct_message_id' => 1], readAt: now());

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unread.notifications', 1));
    }

    /**
     * Read-state separation: reading the feed never touches layer-1 truth. A pending friend
     * request keeps its "needs action" badge at 1 even after every feed row is marked read.
     */
    public function test_marking_feed_read_does_not_consume_the_layer1_friend_request_badge(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $requester->getKey()]);

        $this->actingAs($viewer)->post('/notifications/read-all');

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unread.friendRequests', 1)
                ->where('unread.notifications', 0),
            );
    }

    /**
     * Returning to the feed revalidates it rather than showing the history state Inertia restores,
     * and both props that carry read state have to come back from the partial reload it sends: the
     * rows, and the count the bell and the mark-all button read.
     */
    public function test_the_partial_reload_a_restored_feed_sends_answers_with_fresh_read_state(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        // What the member left behind, and what the browser hands back on the way in.
        $this->actingOnModern($viewer)->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.data.0.read', false)
                ->where('unread.notifications', 1),
            );

        $row->markAsRead(); // what opening the row did, one navigation ago
        $this->freshRequestState();

        $this->actingOnModern($viewer)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'notifications/index',
                'X-Inertia-Partial-Data' => 'feed,unread',
            ])
            ->get('/notifications')
            ->assertOk()
            // A partial reload answers as JSON, so this reads the page object rather than the view's.
            ->assertJsonPath('props.feed.data.0.read', true)
            ->assertJsonPath('props.unread.notifications', 0);
    }

    /**
     * The suite runs classic_default, so a test about the Modern payload has to ask for that
     * surface — the same feed on Classic answers with Blade. Classic's own rendering is
     * Tests\Feature\Notifications\Classic\NotificationFeedListTest.
     */
    private function actingOnModern(Member $viewer): static
    {
        config()->set('openpne.surface_mode', 'modern_default');

        return $this->actingAs($viewer);
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $kind, array $data, ?\DateTimeInterface $createdAt = null, ?\DateTimeInterface $readAt = null): DatabaseNotification
    {
        /** @var DatabaseNotification $row */
        $row = $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $kind === 'direct_message_received' ? DirectMessageReceivedNotification::class : FriendRequestedNotification::class,
            'data' => ['kind' => $kind, ...$data],
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
        ]);

        return $row;
    }
}
