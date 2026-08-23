<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Http\Requests\Member\UpdateNotificationSettingsRequest;
use App\Models\Group;
use App\Models\GroupMember;
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
                ->has('form.groups', 7)
                ->where('form.groups.0.key', 'timeline')
                ->where('form.groups.0.caption', __('%Activity%'))
                // Five, not six: timeline_new_post_community went dormant at the group-talk cutover.
                ->has('form.groups.0.kinds', 5)
                ->where('form.groups.0.kinds.0.kind', 'timeline_new_post')
                ->where('form.groups.0.kinds.1.dependOnNot', 'timeline_new_post')
                ->where('form.groups.0.kinds.2.kind', 'timeline_reply_post')
                ->where('form.groups.0.kinds.3.kind', 'timeline_related_post')
                ->where('form.groups.0.kinds.4.kind', 'timeline_mention')
                ->where('form.groups.0.kinds.4.caption', __('When you are mentioned in a %activity% post'))
                ->where('form.groups.1.key', 'diary')
                ->has('form.groups.1.kinds', 4)
                ->where('form.groups.1.kinds.0.kind', 'diary_new_post')
                ->where('form.groups.1.kinds.1.dependOnNot', 'diary_new_post')
                ->where('form.groups.2.key', 'group_topic')
                ->has('form.groups.2.kinds', 4)
                ->where('form.groups.2.kinds.0.kind', 'group_topic_new_post')
                ->where('form.groups.2.kinds.1.kind', 'group_topic_comment_new_post')
                ->where('form.groups.3.key', 'group_event')
                ->has('form.groups.3.kinds', 4)
                ->where('form.groups.3.kinds.0.kind', 'group_event_new_post')
                ->where('form.groups.4.key', 'group_talk')
                ->has('form.groups.4.kinds', 2)
                ->where('form.groups.4.kinds.0.kind', 'group_talk_mention')
                ->where('form.groups.4.kinds.0.caption', __('When you are mentioned in a %community% talk message (delivered even while the %community% is muted)'))
                // Its web toggle reads the site default, which an OSS install leaves at mentions-only.
                ->where('form.groups.4.kinds.1.kind', 'group_talk_new_message')
                ->where('form.groups.4.kinds.1.web', false)
                ->where('form.groups.4.kinds.1.mail', false)
                ->where('form.groups.5.key', 'friend_link')
                ->has('form.groups.5.kinds', 2)
                ->where('form.groups.5.kinds.0.kind', 'friend_link_confirm')
                ->where('form.groups.5.kinds.0.web', true)
                ->where('form.groups.6.key', 'direct_message')
                ->where('form.groups.6.kinds.0.mail', false)
                ->where('form.groups.6.kinds.1.dependOnNot', 'direct_message_new'),
            );
    }

    /**
     * The "(default)" label's input: true only where the shown value is the site's, which is only
     * ever a kind whose default an administrator can move.
     */
    public function test_the_site_default_flag_marks_an_inherited_value_until_the_member_overrides_it(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, true);

        $this->actingAs($member)->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The overridden channel is the member's own; mail is never the site's to begin with
                // (its default is fixed off), so it is not labelled either.
                ->where('form.groups.4.kinds.1.kind', 'group_talk_new_message')
                ->where('form.groups.4.kinds.1.siteDefault.web', false)
                ->where('form.groups.4.kinds.1.siteDefault.mail', false)
                // A kind with no site default is never labelled, row or no row.
                ->where('form.groups.4.kinds.0.kind', 'group_talk_mention')
                ->where('form.groups.4.kinds.0.siteDefault.web', false)
                ->where('form.groups.4.kinds.0.siteDefault.mail', false),
            );
    }

    public function test_the_modern_page_lists_the_members_muted_rooms(): void
    {
        $member = Member::factory()->create();
        $muted = Group::factory()->create(['name' => 'Anvil']);
        GroupMember::factory()->create(['group_id' => $muted->getKey(), 'member_id' => $member->getKey(), 'is_talk_muted' => true]);
        $audible = Group::factory()->create(['name' => 'Bellows']);
        GroupMember::factory()->create(['group_id' => $audible->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mutedRooms', 1)
                ->where('mutedRooms.0.id', $muted->getKey())
                ->where('mutedRooms.0.name', 'Anvil'),
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
                ->has('form.groups', 7)
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
                ->has('form.groups', 7),
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
            ->assertSee(NotificationKind::GroupTalkMention->caption())
            // Dormant since the group-talk cutover: registered, but off the page.
            ->assertDontSee(NotificationKind::TimelineNewPostCommunity->caption());
    }

    public function test_classic_category_page_explains_the_talk_settings_reach_and_lists_the_muted_rooms(): void
    {
        $member = Member::factory()->create();
        $muted = Group::factory()->create(['name' => 'Anvil']);
        GroupMember::factory()->create(['group_id' => $muted->getKey(), 'member_id' => $member->getKey(), 'is_talk_muted' => true]);
        $audible = Group::factory()->create(['name' => 'Bellows']);
        GroupMember::factory()->create(['group_id' => $audible->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)->get('/member/config?category=notification')
            ->assertOk()
            ->assertSee(__('Applies to every %community% you belong to. To quiet one %community%, use Mute on its talk screen.'))
            ->assertSee(__('Muted %communities%'))
            ->assertSee('Anvil')
            ->assertSee(route('group.talk.show', ['group' => $muted->getKey()]), false)
            ->assertDontSee('Bellows');
    }

    public function test_legacy_config_notification_url_redirects_to_the_category(): void
    {
        $this->get('/member/configNotification')
            ->assertRedirect(route('member.config', ['category' => 'notification']));
    }
}
