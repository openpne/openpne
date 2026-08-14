<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\BroadcastDiaryPosted;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\Push\WebPushNudge;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\PushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

/**
 * Push follows the feed: a `database` send is what dispatches it, so what arrives at a device is
 * decided entirely by the gates that already decided the feed row.
 */
class WebPushNudgeTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.com/subscription/abc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
        $this->fakeWebPushTransport();
    }

    public function test_a_subscribed_member_is_pushed_when_a_feed_row_is_written(): void
    {
        $sender = Member::factory()->create(['name' => 'Kaoru']);
        $recipient = $this->subscribed();

        $this->notifyOfMessage($recipient, $sender);

        $pushes = $this->pushesTo(self::ENDPOINT);
        $this->assertCount(1, $pushes);
        $this->assertSame(sns_name(), $pushes[0]['title']);
        // The feed row's own sentence, so there is no second wording list to drift.
        $this->assertSame(__(':name sent you a message.', ['name' => 'Kaoru']), $pushes[0]['body']);
        $this->assertSame(app_icon_url(192), $pushes[0]['icon']);
        $this->assertSame('openpne-notifications', $pushes[0]['tag']);
        $this->assertSame('/notifications', $pushes[0]['data']['url']);
        $this->assertSame(1, $pushes[0]['data']['unreadCount']);
    }

    public function test_no_push_without_a_vapid_keypair(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        $recipient = $this->subscribed();

        $this->notifyOfMessage($recipient, Member::factory()->create());

        // The feed row is unaffected — the keys switch push off, not the notification.
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame([], $this->webPushTransport->sent);
    }

    public function test_no_push_while_the_member_has_delivery_paused(): void
    {
        $recipient = $this->subscribed();
        $recipient->setPushDelivery(PushDelivery::Disabled);

        $this->notifyOfMessage($recipient, Member::factory()->create());

        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame([], $this->webPushTransport->sent);
    }

    public function test_no_push_to_a_member_with_no_subscribed_device(): void
    {
        $recipient = Member::factory()->create();

        $this->notifyOfMessage($recipient, Member::factory()->create());

        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame([], $this->webPushTransport->sent);
    }

    public function test_an_opted_out_kind_writes_no_row_and_so_pushes_nothing(): void
    {
        $recipient = $this->subscribed();
        $recipient->setNotificationSetting(NotificationKind::DirectMessageNew, NotificationChannel::Web, false);

        $this->notifyOfMessage($recipient, Member::factory()->create());

        $this->assertSame(0, $recipient->notifications()->count());
        $this->assertSame([], $this->webPushTransport->sent);
    }

    public function test_the_body_is_written_in_the_members_own_locale(): void
    {
        $sender = Member::factory()->create(['name' => 'Kaoru']);
        $japanese = $this->subscribed('https://push.example.com/ja');
        $japanese->forceFill(['locale' => 'ja'])->save();
        $english = $this->subscribed('https://push.example.com/en');
        $english->forceFill(['locale' => 'en'])->save();

        $this->notifyOfMessage($japanese, $sender);
        $this->notifyOfMessage($english, $sender);

        $this->assertSame('Kaoru さんからメッセージが届きました。', $this->pushesTo('https://push.example.com/ja')[0]['body']);
        $this->assertSame('Kaoru sent you a message.', $this->pushesTo('https://push.example.com/en')[0]['body']);
    }

    /**
     * The nudge carries the actor's id, not the actor: a member who withdraws between the feed row
     * and the send is exactly the case a serialized model could not restore, and the queued job
     * would fail instead of delivering. Sent directly here — the interleave has no request shape.
     */
    public function test_an_actor_who_withdrew_before_the_send_degrades_to_the_fallback_label(): void
    {
        $recipient = $this->subscribed();
        $goneActorId = Member::factory()->create()->getKey();
        Member::destroy($goneActorId);

        $recipient->notify(new WebPushNudge('direct_message_received', null, (int) $goneActorId));

        $this->assertSame(
            __(':name sent you a message.', ['name' => __('Withdrawn member')]),
            $this->pushesTo(self::ENDPOINT)[0]['body'],
        );
    }

    /** The fan-out writes rows through the same $member->notify(), so push rides along unchanged. */
    public function test_a_diary_broadcast_pushes_every_subscribed_recipient(): void
    {
        $author = Member::factory()->create(['name' => 'Kaoru']);
        $first = $this->subscribed('https://push.example.com/first');
        $second = $this->subscribed('https://push.example.com/second');
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);

        BroadcastDiaryPosted::dispatch((int) $diary->getKey());

        foreach ([$first, $second] as $member) {
            $this->assertSame(1, $member->notifications()->count());
        }
        $expected = __(':name posted a new %diary%.', ['name' => 'Kaoru']);
        $this->assertSame($expected, $this->pushesTo('https://push.example.com/first')[0]['body']);
        $this->assertSame($expected, $this->pushesTo('https://push.example.com/second')[0]['body']);
    }

    public function test_an_expired_subscription_is_deleted_by_the_reports(): void
    {
        $recipient = $this->subscribed();
        $this->webPushTransport->answers(self::ENDPOINT, 410);

        $this->notifyOfMessage($recipient, Member::factory()->create());

        $this->assertSame(0, $recipient->pushSubscriptions()->count());
    }

    public function test_a_failing_transport_leaves_the_request_and_its_single_feed_row_intact(): void
    {
        Exceptions::fake();
        $author = $this->subscribed();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $this->webPushTransport->failsWith(new RuntimeException('push service unreachable'));

        $this->actingAs(Member::factory()->create())
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'Nice'])
            ->assertRedirect("/diary/{$diary->getKey()}");

        // The listener runs inside the job that wrote the row: rethrowing would retry it and
        // duplicate the row, so the failure is reported and dropped instead.
        $this->assertSame(1, $author->notifications()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_failure_before_the_send_is_swallowed_the_same_way(): void
    {
        Exceptions::fake();
        $author = $this->subscribed();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        // Breaks the subscription probe — a guard query, well before anything is dispatched.
        config(['webpush.model' => 'App\\NotAModel']);

        $this->actingAs(Member::factory()->create())
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'Nice'])
            ->assertRedirect("/diary/{$diary->getKey()}");

        $this->assertSame(1, $author->notifications()->count());
        Exceptions::assertReportedCount(1);
    }

    public function test_a_push_does_not_write_a_feed_row_or_push_again(): void
    {
        Exceptions::fake();
        $recipient = $this->subscribed();

        $this->notifyOfMessage($recipient, Member::factory()->create());

        // The nudge sends on WebPushChannel, so it cannot re-enter the database-channel filter —
        // and nothing was swallowed on the way, which is how a re-entry would show up here.
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertCount(1, $this->webPushTransport->sent);
        Exceptions::assertNothingReported();
    }

    private function subscribed(string $endpoint = self::ENDPOINT): Member
    {
        $member = Member::factory()->create();
        $member->updatePushSubscription($endpoint, str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');

        return $member;
    }

    private function notifyOfMessage(Member $recipient, Member $sender): void
    {
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        // The receipt a send materializes: without it the notification's delivery-time check reads
        // this as a message that is not the recipient's, and nothing is delivered to push at all.
        $message->recipients()->create(['recipient_id' => $recipient->getKey()]);

        $recipient->notify(
            (new DirectMessageReceivedNotification($sender, $message))
                ->locale($recipient->locale ?? app()->getLocale()),
        );
    }
}
