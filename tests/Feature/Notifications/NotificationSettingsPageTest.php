<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_page_lists_only_wired_kinds_grouped_by_category(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Mail, false);

        $this->actingAs($member)->get('/m/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/config/notifications')
                ->has('form.groups', 5)
                ->where('form.groups.0.key', 'diary')
                ->has('form.groups.0.kinds', 2)
                ->where('form.groups.0.kinds.0.kind', 'diary_reply_post')
                ->where('form.groups.1.key', 'community_topic')
                ->has('form.groups.1.kinds', 2)
                ->where('form.groups.2.key', 'community_event')
                ->has('form.groups.2.kinds', 2)
                ->where('form.groups.3.key', 'friend_link')
                ->has('form.groups.3.kinds', 2)
                ->where('form.groups.3.kinds.0.kind', 'friend_link_confirm')
                ->where('form.groups.3.kinds.0.web', true)
                ->where('form.groups.4.key', 'message')
                ->where('form.groups.4.kinds.0.mail', false)
                ->where('form.groups.4.kinds.1.dependOnNot', 'message_new'),
            );
    }

    public function test_modern_single_toggle_saves_and_returns_to_the_detail_page(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/m/member/config/notifications', ['settings' => ['friend_link_confirm' => ['mail' => false]]])
            ->assertRedirect(route('member.config.notifications.edit'));

        $this->assertFalse($member->fresh()->wantsNotification(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail));
        // The other channel is untouched (partial map semantics).
        $this->assertTrue($member->fresh()->wantsNotification(NotificationKind::FriendLinkConfirm, NotificationChannel::Web));
    }

    public function test_classic_bulk_save_persists_and_returns_to_the_category_page(): void
    {
        $member = Member::factory()->create();

        // The Classic form posts every rendered control ('0' via the hidden input when unchecked).
        $this->actingAs($member)->post('/member/config/notifications', ['settings' => [
            'friend_link_confirm' => ['web' => '1', 'mail' => '0'],
            'friend_link_complete' => ['web' => '1', 'mail' => '1'],
            'message_new' => ['web' => '0', 'mail' => '0'],
            'message_new_only_friends' => ['web' => '1', 'mail' => '1'],
        ]])
            ->assertRedirect(route('member.config', ['category' => 'notification']))
            ->assertSessionHas('status');

        $fresh = $member->fresh();
        $this->assertFalse($fresh->wantsNotification(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail));
        $this->assertTrue($fresh->wantsNotification(NotificationKind::FriendLinkComplete, NotificationChannel::Mail));
        $this->assertFalse($fresh->wantsNotification(NotificationKind::MessageNew, NotificationChannel::Web));
        $this->assertTrue($fresh->wantsNotification(NotificationKind::MessageNewOnlyFriends, NotificationChannel::Web));
    }

    public function test_an_unwired_kind_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/m/member/config/notifications')
            ->post('/m/member/config/notifications', ['settings' => ['timeline_new_post' => ['mail' => false]]])
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/m/member/config/notifications')
            ->post('/m/member/config/notifications', ['settings' => ['message_new' => ['push' => true]]])
            ->assertSessionHasErrors('settings.message_new');

        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    public function test_classic_category_page_renders_the_form(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=notification')
            ->assertOk()
            ->assertSee('id="member_config_notification"', false)
            ->assertSee('action="'.route('member.config.notifications').'"', false)
            ->assertSee(NotificationKind::FriendLinkConfirm->caption())
            ->assertSee(NotificationKind::MessageNew->caption())
            ->assertDontSee(NotificationKind::TimelineNewPost->caption());
    }

    public function test_legacy_config_notification_url_redirects_to_the_category(): void
    {
        $this->get('/member/configNotification')
            ->assertRedirect(route('member.config', ['category' => 'notification']));
    }
}
