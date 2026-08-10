<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Classic;

use App\Features\Member\MemberConfigCategory;
use App\Features\Notifications\NotificationCenterWindow;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Classic feed: the recentList box, a pager around it, and one open POST per row. There is no
 * OpenPNE 3 screen behind it, so what is asserted here is the box's own contract rather than parity.
 */
class NotificationFeedListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_lists_each_row_as_a_post_to_the_open_route(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $response = $this->actingAs($viewer)->get('/notifications')->assertOk();

        $response->assertViewIs('notifications.index')
            ->assertSee('class="dparts recentList"', false)
            ->assertSee(__(':name sent you a %friend% request.', ['name' => $actor->name]))
            ->assertSee('<form method="POST" action="'.route('notifications.open', $row->getKey()).'" class="notificationFeedRow">', false)
            ->assertSee('name="_token"', false);
    }

    public function test_an_unread_row_is_emphasised_and_a_read_one_is_not(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);
        $this->seedRow($viewer, 'friend_request_accepted', ['accepter_id' => $actor->getKey()], readAt: now());

        $body = (string) $this->actingAs($viewer)->get('/notifications')->assertOk()->getContent();

        $unread = e(__(':name sent you a %friend% request.', ['name' => $actor->name]));
        $read = e(__(':name accepted your %friend% request.', ['name' => $actor->name]));

        $this->assertStringContainsString('<strong>'.$unread.'</strong>', $body);
        $this->assertStringNotContainsString('<strong>'.$read.'</strong>', $body);
        $this->assertStringContainsString($read, $body);
    }

    /**
     * Opening a row marks it read and leaves, so the page the browser keeps for back is stale the
     * moment it is kept. The feed loads the restore refresh for that; the rest of Classic does not,
     * since disabling the back/forward cache is what that script costs a screen.
     */
    public function test_only_the_feed_refreshes_itself_after_a_back_forward_restore(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/notifications')
            ->assertOk()
            ->assertSee('js/classic-refresh-on-restore.js', false);

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('js/classic-refresh-on-restore.js', false);
    }

    public function test_the_pager_brackets_the_list(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $body = (string) $this->actingAs($viewer)->get('/notifications')->assertOk()->getContent();

        $this->assertSame(2, substr_count($body, 'class="pagerRelative"'));
        $this->assertLessThan(strpos($body, '<dl>'), strpos($body, 'class="pagerRelative"'));
        $this->assertGreaterThan(strrpos($body, '</dl>'), strrpos($body, 'class="pagerRelative"'));
    }

    public function test_mark_all_shows_only_while_something_is_unread(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->get('/notifications')
            ->assertSee('action="'.route('notifications.readAll').'"', false)
            ->assertSee(__('Mark all as read'));

        $row->markAsRead();
        $this->freshRequestState(); // the count is memoized per request, and this is the next one

        // Same condition the Modern page hides its button on: nothing left to mark.
        $this->actingAs($viewer)->get('/notifications')
            ->assertDontSee('action="'.route('notifications.readAll').'"', false);
    }

    public function test_a_row_posts_through_to_the_open_action(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedRow($viewer, 'friend_requested', ['requester_id' => $actor->getKey()]);

        $this->actingAs($viewer)->get('/notifications')->assertOk();
        $this->actingAs($viewer)->post(route('notifications.open', $row->getKey()))
            ->assertRedirect('/friend/requests');

        $this->assertNotNull($row->fresh()->read_at);
    }

    /**
     * The feed pages through everything, so its mark-all follows the whole unread set — not the
     * header center's 20-row window. An unread row the center cannot badge still has something for
     * this button to mark.
     */
    public function test_mark_all_survives_an_unread_row_older_than_the_centers_window(): void
    {
        $viewer = Member::factory()->create();
        $this->seedRow($viewer, 'diary_commented', [], createdAt: now()->subSecond());
        foreach (range(1, NotificationCenterWindow::LIMIT) as $ignored) {
            $this->seedRow($viewer, 'diary_commented', [], readAt: now());
        }

        // The center's window is the newest 20, all read, so its badges are empty.
        $this->actingAs($viewer)->get('/notifications')
            ->assertOk()
            ->assertDontSee('id="nc_icon', false)
            ->assertSee('action="'.route('notifications.readAll').'"', false);
    }

    public function test_an_empty_feed_says_so_and_drops_the_pager(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/notifications')
            ->assertOk()
            ->assertSee(__('No notifications yet.'))
            ->assertDontSee('class="pagerRelative"', false);
    }

    /** The way to what decides this feed's contents, independent of whether it has any. */
    public function test_the_settings_link_shows_whether_or_not_the_feed_has_rows(): void
    {
        $viewer = Member::factory()->create();
        $settings = route('member.config', ['category' => MemberConfigCategory::Notification->value]);

        $this->actingAs($viewer)->get('/notifications')
            ->assertOk()
            ->assertSee('href="'.$settings.'"', false)
            ->assertSee(__('Notification settings'));

        $this->seedRow($viewer, 'diary_commented', []);

        $this->actingAs($viewer)->get('/notifications')
            ->assertOk()
            ->assertSee('href="'.$settings.'"', false);
    }

    /**
     * Where a row leads is resolved by the open POST, not by listing it — so a page of rows costs
     * what a handful does. The message kind is the canary: resolving its target reads the inbox
     * receipt, which a per-row evaluation would show up as one query per row.
     */
    public function test_a_page_of_rows_costs_what_a_handful_does(): void
    {
        $few = $this->memberWithMessageRows(3);
        $many = $this->memberWithMessageRows(12);

        $this->assertSame($this->queryCountFor($few), $this->queryCountFor($many));
    }

    private function memberWithMessageRows(int $count): Member
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count($count)->create() as $index => $sender) {
            $this->seedRow($viewer, 'message_received', [
                'sender_id' => $sender->getKey(),
                'message_id' => $index + 1,
            ]);
        }

        return $viewer;
    }

    private function queryCountFor(Member $viewer): int
    {
        $this->actingAs($viewer)->get('/notifications')->assertOk(); // warm the process-wide caches first
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/notifications')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $kind, array $data, ?\DateTimeInterface $readAt = null, ?\DateTimeInterface $createdAt = null): DatabaseNotification
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
