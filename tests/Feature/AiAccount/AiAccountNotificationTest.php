<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

/**
 * Asserted on NotificationSent rather than on a delivered mail: the account's address column is
 * null, so the mail channel would drop it anyway and the absence would pass with the listener
 * deleted.
 */
class AiAccountNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $via
     * @return list<string>
     */
    private function channelsReached(Member $notifiable, array $via): array
    {
        Event::fake([NotificationSent::class]);

        $notifiable->notify(new ChannelProbeNotification($via));

        return Event::dispatched(NotificationSent::class)
            ->map(fn (array $arguments): string => $arguments[0]->channel)
            ->values()
            ->all();
    }

    public function test_mail_and_push_are_dropped_for_an_ai_account(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $reached = $this->channelsReached($aiAccount, ['mail', 'database', WebPushChannel::class]);

        $this->assertSame(['database'], $reached);
    }

    public function test_the_feed_row_is_still_written(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $aiAccount->notify(new ChannelProbeNotification(['mail', 'database']));

        $this->assertSame(1, $aiAccount->notifications()->count());
    }

    public function test_an_ordinary_member_is_unaffected(): void
    {
        $member = Member::factory()->create();

        $reached = $this->channelsReached($member, ['mail', 'database']);

        $this->assertSame(['mail', 'database'], $reached);
    }
}

/** A notification that exists only to be watched: it goes wherever the test says and says nothing. */
final class ChannelProbeNotification extends Notification
{
    /** @param  list<string>  $channels */
    public function __construct(private readonly array $channels) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('probe');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'probe'];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('probe')->body('probe');
    }
}
