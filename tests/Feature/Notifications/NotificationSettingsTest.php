<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
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

    public function test_rows_cascade_on_member_delete(): void
    {
        $member = Member::factory()->create();
        $member->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Mail, false);

        $member->delete();

        $this->assertDatabaseCount('member_notification_settings', 0);
    }
}
