<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_absent_row_means_enabled(): void
    {
        $member = Member::factory()->create();

        $this->assertTrue($member->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Mail));
        $this->assertTrue($member->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Web));
        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    public function test_opt_out_is_stored_and_read_back(): void
    {
        $member = Member::factory()->create();

        $member->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Mail, false);

        $this->assertFalse($member->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Mail));
        // Channels are independent rows: the web channel keeps its default.
        $this->assertTrue($member->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Web));
    }

    public function test_flipping_back_updates_the_row_in_place(): void
    {
        $member = Member::factory()->create();

        $member->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail, false);
        $member->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail, true);

        $this->assertTrue($member->wantsNotification(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail));
        $this->assertDatabaseCount('member_notification_settings', 1);
    }

    public function test_settings_are_per_member(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        $alice->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Mail, false);

        $this->assertFalse($alice->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Mail));
        $this->assertTrue($bob->wantsNotification(NotificationKind::DirectMessageNew, NotificationChannel::Mail));
    }

    public function test_a_site_default_kind_reads_the_admin_setting_while_no_row_exists(): void
    {
        $member = Member::factory()->create();

        $this->assertFalse($member->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web));

        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);

        $this->assertTrue($member->fresh()->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web));
        // Mail is off whatever the site says: a mail per chat message is the member's own decision.
        $this->assertFalse($member->fresh()->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Mail));
    }

    public function test_saving_the_current_default_stores_no_row_for_a_site_default_kind(): void
    {
        $member = Member::factory()->create();

        $member->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, false);

        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    public function test_saving_the_other_value_stores_one_row_and_saving_the_default_back_removes_it(): void
    {
        $member = Member::factory()->create();

        $member->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, true);
        $this->assertDatabaseCount('member_notification_settings', 1);
        $this->assertTrue($member->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web));

        $member->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, false);
        $this->assertDatabaseCount('member_notification_settings', 0);
    }

    public function test_an_explicit_row_survives_an_admin_flip(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, true);

        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::Mentions->value);

        $this->assertDatabaseCount('member_notification_settings', 1);
        $this->assertTrue($member->fresh()->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web));
    }

    /**
     * The reason a row is an override rather than a copy: the Classic settings form posts every kind on
     * every save, so a member who merely saved the page under `all` must still follow the administrator
     * back to `mentions` — only a member who chose differently keeps their answer.
     */
    public function test_a_bulk_save_under_all_does_not_freeze_the_site_default_into_a_row(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/notifications', [
            'settings' => [
                NotificationKind::GroupTalkNewMessage->value => ['web' => true, 'mail' => false],
            ],
        ])->assertRedirect();

        // Web is the site's to move, so the save leaves it no row; mail's default is fixed, so its
        // row is stored like any other kind's and is no one's to inherit from.
        $this->assertDatabaseMissing('member_notification_settings', [
            'kind' => NotificationKind::GroupTalkNewMessage->value,
            'channel' => NotificationChannel::Web->value,
        ]);
        $this->assertDatabaseHas('member_notification_settings', [
            'kind' => NotificationKind::GroupTalkNewMessage->value,
            'channel' => NotificationChannel::Mail->value,
            'is_enabled' => false,
        ]);

        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::Mentions->value);

        $this->assertFalse($member->fresh()->wantsNotification(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web));
    }

    public function test_rows_cascade_on_member_delete(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Mail, false);

        $member->delete();

        $this->assertDatabaseCount('member_notification_settings', 0);
    }
}
