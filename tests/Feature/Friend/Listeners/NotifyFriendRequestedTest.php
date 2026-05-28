<?php

namespace Tests\Feature\Friend\Listeners;

use App\Features\Friend\Events\FriendRequested;
use App\Listeners\Friend\NotifyFriendRequested;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
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
}
