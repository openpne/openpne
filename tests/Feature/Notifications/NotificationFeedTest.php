<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_own_notifications_newest_first_with_hydrated_actors(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $older = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()], createdAt: now()->subHour());
        $newer = $this->seedRow($viewer, 'message_received', ['sender_id' => $actor->getKey(), 'message_id' => 12], createdAt: now());

        $this->actingAs($viewer)->get('/m/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('notifications/index')
                ->where('feed.data.0.id', $newer->getKey())
                ->where('feed.data.0.kind', 'message_received')
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

        $this->actingAs($viewer)->get('/m/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('feed.meta.total', 0));
    }

    public function test_withdrawn_actor_degrades_to_a_null_actor(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $actor->delete();

        $this->actingAs($viewer)->get('/m/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('feed.data.0.actor', null));
    }

    public function test_open_marks_the_row_read_and_redirects_to_its_target(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'message_received', ['sender_id' => $actor->getKey(), 'message_id' => 34]);

        $this->actingAs($viewer)->post("/m/notifications/{$row->getKey()}/open")
            ->assertRedirect('/m/message/read/34');

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_open_redirects_a_friend_request_to_the_requests_page(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->post("/m/notifications/{$row->getKey()}/open")
            ->assertRedirect('/m/friend/manage');
    }

    public function test_open_falls_back_to_the_feed_when_the_target_is_gone(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_request_accepted', ['accepter_id' => $actor->getKey()]);
        $actor->delete();

        $this->actingAs($viewer)->post("/m/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_open_rejects_another_members_notification(): void
    {
        [$viewer, $other, $actor] = Member::factory()->count(3)->create()->all();
        $row = $this->seedRow($other, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->post("/m/notifications/{$row->getKey()}/open")->assertNotFound();

        $this->assertNull($row->fresh()->read_at);
    }

    public function test_read_all_marks_only_own_unread_rows(): void
    {
        [$viewer, $other, $actor] = Member::factory()->count(3)->create()->all();
        $mine = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $theirs = $this->seedRow($other, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->from('/m/notifications')->post('/m/notifications/read-all')
            ->assertRedirect('/m/notifications');

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_unread_shared_prop_counts_unread_feed_rows(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $this->seedRow($viewer, 'message_received', ['sender_id' => $actor->getKey(), 'message_id' => 1], readAt: now());

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

        $this->actingAs($viewer)->post('/m/notifications/read-all');

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unread.friendRequests', 1)
                ->where('unread.notifications', 0),
            );
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $kind, array $data, ?\DateTimeInterface $createdAt = null, ?\DateTimeInterface $readAt = null): DatabaseNotification
    {
        /** @var DatabaseNotification $row */
        $row = $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $kind === 'message_received' ? MessageReceivedNotification::class : FriendRequestedNotification::class,
            'data' => ['kind' => $kind, ...$data],
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
        ]);

        return $row;
    }
}
