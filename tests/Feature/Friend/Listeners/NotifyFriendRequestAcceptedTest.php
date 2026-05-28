<?php

namespace Tests\Feature\Friend\Listeners;

use App\Features\Friend\Events\FriendRequestAccepted;
use App\Listeners\Friend\NotifyFriendRequestAccepted;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestAcceptedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyFriendRequestAcceptedTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_notification_to_original_requester_via_mail_and_database(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new NotifyFriendRequestAccepted)->handle(new FriendRequestAccepted($alice, $bob));

        Notification::assertSentTo(
            $alice,
            FriendRequestAcceptedNotification::class,
            function (FriendRequestAcceptedNotification $notification, array $channels) use ($bob) {
                return $notification->accepter->is($bob)
                    && in_array('mail', $channels, true)
                    && in_array('database', $channels, true);
            },
        );
    }

    public function test_does_not_notify_the_accepter(): void
    {
        Notification::fake();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new NotifyFriendRequestAccepted)->handle(new FriendRequestAccepted($alice, $bob));

        Notification::assertNotSentTo($bob, FriendRequestAcceptedNotification::class);
    }
}
