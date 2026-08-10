<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\NotificationCenterCounts;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Message\MessageReceivedNotification;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A switched-off unit's rows leave every notification surface — feed, header center, badges,
 * settings — and come back untouched when it returns. Filtering is by `notifications.type`, the
 * class the row was written by, so the rows themselves are never rewritten.
 */
class FeatureNotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function switchMessages(bool $on): void
    {
        $this->setSnsSetting(Feature::Message->settingKey(), $on);
        $this->freshRequestState();
    }

    private function seedMessageRow(Member $viewer, Member $sender, ?Carbon $createdAt = null): DatabaseNotification
    {
        return $this->seedRow($viewer, MessageReceivedNotification::class, [
            'kind' => 'message_received',
            'sender_id' => $sender->getKey(),
            'message_id' => 1,
        ], $createdAt);
    }

    private function seedFriendRow(Member $viewer, Member $requester): DatabaseNotification
    {
        return $this->seedRow($viewer, FriendRequestedNotification::class, [
            'kind' => 'friend_requested',
            'requester_id' => $requester->getKey(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $viewer, string $type, array $data, ?Carbon $createdAt = null): DatabaseNotification
    {
        /** @var DatabaseNotification $row */
        $row = $viewer->notifications()->create(array_filter([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data,
            'read_at' => null,
            // Same-second rows tie on created_at and MySQL orders ties arbitrarily; an
            // order-sensitive test states its timeline explicitly (the #474 deflake pattern).
            'created_at' => $createdAt,
        ], static fn ($value) => $value !== null));

        return $row;
    }

    private function actingOnModern(Member $viewer): static
    {
        config()->set('openpne.surface_mode', 'modern_default');

        return $this->actingAs($viewer);
    }

    public function test_the_feed_drops_a_switched_off_units_rows(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedMessageRow($viewer, $actor);
        $friendRow = $this->seedFriendRow($viewer, $actor);

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('feed.meta.total', 2));

        $this->switchMessages(false);

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.meta.total', 1)
                ->where('feed.data.0.id', $friendRow->getKey()),
            );
    }

    public function test_opening_a_hidden_row_answers_404_rather_than_redirecting_into_one(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedMessageRow($viewer, $actor);

        $this->switchMessages(false);

        $this->actingAs($viewer)->post("/notifications/{$row->getKey()}/open")->assertNotFound();
        $this->assertNull($row->fresh()->read_at);
    }

    public function test_mark_all_read_preserves_hidden_rows_and_they_return_unread(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        // The hidden row is explicitly older: the feed assertion below is order-sensitive.
        $hidden = $this->seedMessageRow($viewer, $actor, now()->subMinute());
        $visible = $this->seedFriendRow($viewer, $actor);

        $this->switchMessages(false);
        $this->actingAs($viewer)->post('/notifications/read-all');

        $this->assertNull($hidden->fresh()->read_at, 'A row the member cannot see must not be marked read on their behalf.');
        $this->assertNotNull($visible->fresh()->read_at);

        $this->switchMessages(true);

        $this->actingOnModern($viewer)->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feed.meta.total', 2)
                ->where('feed.data.0.id', $visible->getKey())
                ->where('feed.data.0.read', true)
                ->where('feed.data.1.id', $hidden->getKey())
                ->where('feed.data.1.read', false),
            );
    }

    public function test_the_center_panel_and_its_badges_drop_hidden_rows(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedMessageRow($viewer, $actor);
        $this->seedFriendRow($viewer, $actor);

        $this->assertSame(
            ['message' => 1, 'friend' => 1, 'other' => 0],
            $this->app->make(NotificationCenterCounts::class)->for($viewer),
        );

        $this->switchMessages(false);

        $this->actingAs($viewer)->get(route('notifications.center'))
            ->assertOk()
            ->assertSee(__(':name sent you a %friend% request.', ['name' => $actor->name]))
            ->assertDontSee(__(':name sent you a message.', ['name' => $actor->name]));

        $this->freshRequestState();
        $this->assertSame(
            ['message' => 0, 'friend' => 1, 'other' => 0],
            $this->app->make(NotificationCenterCounts::class)->for($viewer),
        );
    }

    public function test_the_shared_unread_badge_excludes_hidden_rows(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedMessageRow($viewer, $actor);
        $this->seedFriendRow($viewer, $actor);

        $this->switchMessages(false);

        $this->actingOnModern($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unread.notifications', 1));
    }

    public function test_the_modern_settings_page_omits_a_switched_off_units_kinds(): void
    {
        $member = Member::factory()->create();

        $this->switchMessages(false);

        $this->actingOnModern($member)->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('form.groups', 5)
                ->where('form.groups.4.key', 'friend_link'),
            );
    }

    public function test_the_classic_settings_page_omits_a_switched_off_units_kinds(): void
    {
        $member = Member::factory()->create();

        $this->switchMessages(false);

        $groups = $this->actingAs($member)->get('/member/config?category=notification')
            ->assertOk()
            ->viewData('notificationGroups');

        $this->assertSame(['timeline', 'diary', 'community_topic', 'community_event', 'friend_link'], array_column($groups, 'key'));
    }
}
