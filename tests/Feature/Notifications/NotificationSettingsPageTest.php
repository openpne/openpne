<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Http\Requests\Member\UpdateNotificationSettingsRequest;
use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

class NotificationSettingsPageTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    public function test_modern_page_lists_only_wired_kinds_grouped_by_category(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Mail, false);

        $this->actingAs($member)->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/config/notifications')
                ->has('form.groups', 6)
                ->where('form.groups.0.key', 'timeline')
                ->where('form.groups.0.caption', __('%Activity%'))
                ->has('form.groups.0.kinds', 6)
                ->where('form.groups.0.kinds.0.kind', 'timeline_new_post')
                ->where('form.groups.0.kinds.1.dependOnNot', 'timeline_new_post')
                ->where('form.groups.0.kinds.2.kind', 'timeline_new_post_community')
                ->where('form.groups.0.kinds.3.kind', 'timeline_reply_post')
                ->where('form.groups.0.kinds.4.kind', 'timeline_related_post')
                ->where('form.groups.0.kinds.5.kind', 'timeline_mention')
                ->where('form.groups.0.kinds.5.caption', __('When you are mentioned in a %activity% post'))
                ->where('form.groups.1.key', 'diary')
                ->has('form.groups.1.kinds', 4)
                ->where('form.groups.1.kinds.0.kind', 'diary_new_post')
                ->where('form.groups.1.kinds.1.dependOnNot', 'diary_new_post')
                ->where('form.groups.2.key', 'community_topic')
                ->has('form.groups.2.kinds', 4)
                ->where('form.groups.2.kinds.0.kind', 'community_topic_new_post')
                ->where('form.groups.2.kinds.1.kind', 'community_topic_comment_new_post')
                ->where('form.groups.3.key', 'community_event')
                ->has('form.groups.3.kinds', 4)
                ->where('form.groups.3.kinds.0.kind', 'community_event_new_post')
                ->where('form.groups.4.key', 'friend_link')
                ->has('form.groups.4.kinds', 2)
                ->where('form.groups.4.kinds.0.kind', 'friend_link_confirm')
                ->where('form.groups.4.kinds.0.web', true)
                ->where('form.groups.5.key', 'direct_message')
                ->where('form.groups.5.kinds.0.mail', false)
                ->where('form.groups.5.kinds.1.dependOnNot', 'direct_message_new'),
            );
    }

    public function test_the_modern_page_carries_push_props_when_the_site_is_configured(): void
    {
        $this->configureVapid();

        $this->actingAs(Member::factory()->create())->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/config/notifications')
                // Shared VAPID key + the page's own pause-switch state (default on).
                ->where('push.vapidPublicKey', config('webpush.vapid.public_key'))
                ->where('pushSettings.enabled', true)
                // The push section does not touch the catalog grid.
                ->has('form.groups', 6)
                ->where('form.groups.0.kinds.0.kind', 'timeline_new_post'),
            );
    }

    public function test_the_push_shared_prop_is_absent_without_a_vapid_keypair(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $this->actingAs(Member::factory()->create())->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('push', null)
                // The controller still ships the pause-switch value; the UI hides on the null shared prop.
                ->where('pushSettings.enabled', true)
                ->has('form.groups', 6),
            );
    }

    public function test_modern_single_toggle_saves_and_returns_to_the_detail_page(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/member/config/notifications', ['settings' => ['friend_link_confirm' => ['mail' => false]]])
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
            'direct_message_new' => ['web' => '0', 'mail' => '0'],
            'direct_message_new_only_friends' => ['web' => '1', 'mail' => '1'],
        ]])
            ->assertRedirect(route('member.config', ['category' => 'notification']))
            ->assertSessionHas('status');

        $fresh = $member->fresh();
        $this->assertFalse($fresh->wantsNotification(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail));
        $this->assertTrue($fresh->wantsNotification(NotificationKind::FriendLinkComplete, NotificationChannel::Mail));
        $this->assertFalse($fresh->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Web));
        $this->assertTrue($fresh->wantsNotification(NotificationKind::DirectMessageNewOnlyFriends, NotificationChannel::Web));
    }

    public function test_only_wired_kinds_are_writable(): void
    {
        // Every registered kind is wired today, so the rule is pinned against the registry rather
        // than against one dormant kind: an unwired kind added later must not become writable by
        // the allowlist drifting away from wiredCases().
        $this->assertSame(
            array_map(fn (NotificationKind $kind): string => $kind->value, NotificationKind::wiredCases()),
            $this->writableKinds(),
        );

        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/member/config/notifications')
            ->post('/member/config/notifications', ['settings' => ['not_a_kind' => ['mail' => false]]])
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    /** @return list<string> the kinds the form request's `array:` allowlist accepts */
    private function writableKinds(): array
    {
        $rules = (new UpdateNotificationSettingsRequest)->rules()['settings'];
        $allowlist = collect($rules)->first(fn (string $rule): bool => str_starts_with($rule, 'array:'));

        return explode(',', substr((string) $allowlist, strlen('array:')));
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/member/config/notifications')
            ->post('/member/config/notifications', ['settings' => ['direct_message_new' => ['push' => true]]])
            ->assertSessionHasErrors('settings.direct_message_new');

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
            ->assertSee(NotificationKind::DirectMessageNew->caption())
            ->assertSee(NotificationKind::TimelineNewPost->caption())
            ->assertSee(NotificationKind::TimelineNewPostCommunity->caption());
    }

    public function test_legacy_config_notification_url_redirects_to_the_category(): void
    {
        $this->get('/member/configNotification')
            ->assertRedirect(route('member.config', ['category' => 'notification']));
    }
}
