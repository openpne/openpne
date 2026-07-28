<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Features\Notifications\NotificationCenterWindow;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Profile;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Message\MessageReceivedNotification;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OpenPNE 3's `#notificationCenter`: the header sprite, its three badges, and the panel they head.
 * Two things are load-bearing — who gets it at all (the Classic shell also renders for a guest and
 * for an error page, where there is nobody to count for), and that the sprite stays ONE control
 * that opens in place rather than three that navigate.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'classic_default');
    }

    public function test_the_sprite_is_one_trigger_that_falls_back_to_the_feed(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get('/')->assertOk();

        // The trigger is a real link, so the control works before the script does — and there is no
        // image map: OpenPNE 3's icons were indicators, never three separate destinations.
        $response->assertSee('id="notificationCenter"', false)
            ->assertSee('href="'.e(route('notifications.index')).'"', false)
            ->assertSee('data-notification-center-url="'.e(route('notifications.center')).'"', false)
            ->assertSee('<img class="ncbutton" src="'.e(asset('images/NOTIFY_CENTER.png')).'" width="92" height="32" alt="">', false)
            ->assertDontSee('usemap', false)
            ->assertDontSee('<area', false);
    }

    public function test_the_panel_and_its_script_ship_with_the_shell(): void
    {
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="notificationCenterDetail"', false)
            ->assertSee('id="notificationCenterLoading"', false)
            ->assertSee('id="notificationCenterError"', false)
            ->assertSee(__('There is no new notification.'))
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-controls="notificationCenterDetail"', false)
            // The sprite carries no alt, so the name has to live on the trigger.
            ->assertSee('aria-label="'.e(__('Notification Center')).'"', false)
            ->assertSee(e(asset('js/classic-notification-center.js')), false);
    }

    /**
     * The badges partition one set of rows. Before this they were counted off three different
     * sources, so an unread message was counted by its own badge AND by the third one.
     */
    public function test_the_three_badges_partition_the_panels_rows_without_overlap(): void
    {
        $viewer = Member::factory()->create();
        $actor = Member::factory()->create();
        $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received', 'sender_id' => $actor->getKey()]);
        $this->seedRow($viewer, FriendRequestedNotification::class, ['kind' => 'friend_requested', 'requester_id' => $actor->getKey()]);
        $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'diary_commented', 'commenter_id' => $actor->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span id="nc_icon1">1</span>', $content);
        $this->assertStringContainsString('<span id="nc_icon2">1</span>', $content);
        // One diary comment — not three. The message and the request belong to the other two.
        $this->assertStringContainsString('<span id="nc_icon3">1</span>', $content);
    }

    /**
     * The badges count the window the panel shows, not the whole table. OpenPNE 3 sliced its store
     * to 20 as it wrote, so its counts and its list saw the same items by construction; counting
     * past the window would badge events the panel has no room to account for.
     */
    public function test_an_unread_event_older_than_the_panels_window_is_not_badged(): void
    {
        $viewer = Member::factory()->create();
        $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'diary_commented']);
        foreach (range(1, NotificationCenterWindow::LIMIT) as $ignored) {
            $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'diary_commented'], readAt: now());
        }

        // The one unread row is the oldest, so the newest 20 — all read — are the whole window.
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="notificationCenter"', false)
            ->assertDontSee('id="nc_icon3"', false);
    }

    public function test_only_the_unread_rows_inside_the_window_are_partitioned(): void
    {
        $viewer = Member::factory()->create();
        $actor = Member::factory()->create();
        // Older than the window, and unread — must not reach a badge.
        $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received', 'sender_id' => $actor->getKey()]);
        foreach (range(1, NotificationCenterWindow::LIMIT - 1) as $ignored) {
            $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'diary_commented'], readAt: now());
        }
        // Inside the window: one unread, one read.
        $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'diary_commented'], readAt: now());
        $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received', 'sender_id' => $actor->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span id="nc_icon1">1</span>', $content);
        $this->assertStringNotContainsString('id="nc_icon3"', $content);
    }

    /** A kind nobody has classified still has to reach a badge rather than vanish from all three. */
    public function test_an_unclassified_row_lands_in_the_third_badge(): void
    {
        $viewer = Member::factory()->create();
        $this->seedRow($viewer, DiaryCommentedNotification::class, ['kind' => 'something_added_later']);

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('<span id="nc_icon3">1</span>', false);
    }

    public function test_a_badge_is_absent_while_its_count_is_zero(): void
    {
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="notificationCenter"', false)
            ->assertDontSee('id="nc_icon1"', false)
            ->assertDontSee('id="nc_icon2"', false)
            ->assertDontSee('id="nc_icon3"', false);
    }

    /** The window is the ceiling, so a badge never has more digits than the skin sized it for. */
    public function test_a_badge_cannot_exceed_the_windows_cap(): void
    {
        $viewer = Member::factory()->create();
        $actor = Member::factory()->create();
        foreach (range(1, NotificationCenterWindow::LIMIT + 30) as $ignored) {
            $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received', 'sender_id' => $actor->getKey()]);
        }

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('<span id="nc_icon1">'.NotificationCenterWindow::LIMIT.'</span>', false);
    }

    /** Reading the feed clears the badges, because they count the same unread rows it lists. */
    public function test_reading_everything_clears_the_badges(): void
    {
        $viewer = Member::factory()->create();
        $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received']);

        $this->actingAs($viewer)->post(route('notifications.readAll'));
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/')->assertOk()->assertDontSee('id="nc_icon1"', false);
    }

    public function test_a_guest_on_a_web_public_page_gets_no_notification_center(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $profile = Profile::factory()->create(['is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'public-value', 'visibility' => Visibility::Open,
        ]);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('id="globalNav"', false)
            ->assertDontSee('id="notificationCenter"', false);
    }

    public function test_a_signed_in_member_keeps_the_notification_center_on_an_error_page(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);
        $this->seedRow($viewer, MessageReceivedNotification::class, ['kind' => 'message_received', 'sender_id' => $sender->getKey()]);

        // The Classic error screen is the site's own shell (see ClassicErrorPageTest), so the
        // header it carries is the whole header.
        $this->actingAs($viewer)->get('/diary/999999')
            ->assertNotFound()
            ->assertSee('id="notificationCenter"', false)
            ->assertSee('id="nc_icon1"', false);
    }

    public function test_a_guest_error_page_has_no_notification_center(): void
    {
        $this->get('/diary/999999')
            ->assertRedirect(); // a guest is sent to the login form before the diary is looked up

        $this->get('/no-such-url-at-all')
            ->assertNotFound()
            ->assertDontSee('id="notificationCenter"', false);
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $type, array $data, ?\DateTimeInterface $readAt = null): void
    {
        $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data,
            'read_at' => $readAt,
        ]);
    }
}
