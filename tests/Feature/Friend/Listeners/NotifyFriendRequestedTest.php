<?php

namespace Tests\Feature\Friend\Listeners;

use App\Features\Friend\Events\FriendRequested;
use App\Listeners\Friend\NotifyFriendRequested;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyFriendRequestedTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_notification_to_target_via_mail_and_database(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new NotifyFriendRequested)->handle(new FriendRequested($alice, $bob));

        Notification::assertSentTo(
            $bob,
            FriendRequestedNotification::class,
            function (FriendRequestedNotification $notification, array $channels) use ($alice) {
                return $notification->requester->is($alice)
                    && in_array('mail', $channels, true)
                    && in_array('database', $channels, true);
            },
        );
    }

    public function test_does_not_notify_the_requester(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new NotifyFriendRequested)->handle(new FriendRequested($alice, $bob));

        Notification::assertNotSentTo($alice, FriendRequestedNotification::class);
    }

    public function test_event_dispatch_reaches_listener_via_auto_discovery(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        FriendRequested::dispatch($alice, $bob);

        Notification::assertSentTo($bob, FriendRequestedNotification::class);
    }

    public function test_mail_opt_out_keeps_the_database_record(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $bob->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail, false);

        (new NotifyFriendRequested)->handle(new FriendRequested($alice, $bob));

        Notification::assertSentTo(
            $bob,
            FriendRequestedNotification::class,
            fn (FriendRequestedNotification $notification, array $channels) => $channels === ['database'],
        );
    }

    public function test_web_opt_out_keeps_the_mail(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $bob->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Web, false);

        (new NotifyFriendRequested)->handle(new FriendRequested($alice, $bob));

        Notification::assertSentTo(
            $bob,
            FriendRequestedNotification::class,
            fn (FriendRequestedNotification $notification, array $channels) => $channels === ['mail'],
        );
    }

    public function test_opting_out_of_both_channels_sends_nothing(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $bob->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Mail, false);
        $bob->setNotificationSetting(NotificationKind::FriendLinkConfirm, NotificationChannel::Web, false);

        (new NotifyFriendRequested)->handle(new FriendRequested($alice, $bob));

        Notification::assertNotSentTo($bob, FriendRequestedNotification::class);
    }
}
