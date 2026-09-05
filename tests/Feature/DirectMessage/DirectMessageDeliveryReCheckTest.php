<?php

declare(strict_types=1);

namespace Tests\Feature\DirectMessage;

use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every case here changes the world between the send and the delivery, and asks what reached the
 * transport and the feed.
 */
class DirectMessageDeliveryReCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Self-contained mailer: sentMailCount() reads the array transport's buffer, and the
        // environment's MAIL_MAILER must not decide whether that exists.
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
    }

    /** @var array{sender: Member, recipient: Member, message: DirectMessage} */
    private array $sent;

    /**
     * Send the way SendDirectMessage does — one row, one receipt, one notification — and hand back
     * the jobs waiting to deliver it.
     *
     * @return Collection<int, SendQueuedNotifications>
     */
    private function queuedSend(): Collection
    {
        Queue::fake();

        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'body' => 'meet me at six']);
        $message->recipients()->create(['recipient_id' => $recipient->getKey()]);
        $this->sent = ['sender' => $sender, 'recipient' => $recipient, 'message' => $message];

        $recipient->notify(new DirectMessageReceivedNotification($sender, $message));

        $jobs = Queue::pushed(SendQueuedNotifications::class);
        // One job per channel, each carrying its own: this is what leaves via() unreachable later.
        $this->assertSame([['mail'], ['database']], $jobs->pluck('channels')->all());

        return $jobs;
    }

    /**
     * @param  Collection<int, SendQueuedNotifications>  $jobs
     */
    private function runQueued(Collection $jobs): void
    {
        $manager = $this->app->make(ChannelManager::class);
        foreach ($jobs as $job) {
            // Through a serialization round trip, because that is what restores the notifiable from
            // its identifier — the recipient the re-check reads is the row as it stands now, not the
            // instance the send happened to hold.
            unserialize(serialize($job))->handle($manager);
        }
    }

    /** Mails that reached the transport (mail.default=array), which Mail::fake() would not see. */
    private function sentMailCount(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    private function assertDelivered(): void
    {
        $this->assertSame(1, $this->sentMailCount(), 'the mail should have been delivered');
        $this->assertDatabaseCount('notifications', 1);
    }

    private function assertNotDelivered(): void
    {
        $this->assertSame(0, $this->sentMailCount(), 'the body must not be carried out by mail');
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_an_ordinary_send_delivers_the_mail_and_the_feed_row(): void
    {
        $this->runQueued($this->queuedSend());

        $this->assertDelivered();
    }

    public function test_a_block_raised_after_the_send_stops_both_channels(): void
    {
        $jobs = $this->queuedSend();
        DB::table('member_blocks')->insert([
            'blocker_id' => $this->sent['recipient']->getKey(),
            'blocked_id' => $this->sent['sender']->getKey(),
            'created_at' => now(),
        ]);

        $this->runQueued($jobs);

        $this->assertNotDelivered();
    }

    public function test_a_block_by_the_sender_stops_both_channels_too(): void
    {
        $jobs = $this->queuedSend();
        DB::table('member_blocks')->insert([
            'blocker_id' => $this->sent['sender']->getKey(),
            'blocked_id' => $this->sent['recipient']->getKey(),
            'created_at' => now(),
        ]);

        $this->runQueued($jobs);

        $this->assertNotDelivered();
    }

    public function test_a_recipient_banned_after_the_send_receives_nothing(): void
    {
        $jobs = $this->queuedSend();
        $this->sent['recipient']->forceFill(['is_login_rejected' => true])->save();

        $this->runQueued($jobs);

        $this->assertNotDelivered();
    }

    public function test_a_receipt_purged_before_delivery_stops_both_channels(): void
    {
        $jobs = $this->queuedSend();
        $this->sent['message']->recipients()->update(['recipient_purged_at' => now(), 'recipient_deleted_at' => now()]);

        $this->runQueued($jobs);

        $this->assertNotDelivered();
    }

    public function test_a_receipt_only_trashed_before_delivery_still_arrives(): void
    {
        $jobs = $this->queuedSend();
        $this->sent['message']->recipients()->update(['recipient_deleted_at' => now()]);

        $this->runQueued($jobs);

        $this->assertDelivered();
    }

    public function test_the_unit_switched_off_before_delivery_sends_neither(): void
    {
        $jobs = $this->queuedSend();
        $this->setSnsSetting(Feature::DirectMessage->settingKey(), false);
        $this->freshRequestState();

        $this->runQueued($jobs);

        $this->assertNotDelivered();
    }

    public function test_a_message_read_before_delivery_keeps_the_mail_and_writes_no_feed_row(): void
    {
        $jobs = $this->queuedSend();
        $this->sent['message']->recipients()->update(['read_at' => now()]);

        $this->runQueued($jobs);

        $this->assertSame(1, $this->sentMailCount());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_job_is_dropped_when_its_models_are_missing(): void
    {
        $jobs = $this->queuedSend();

        $this->assertTrue($jobs->every(fn (SendQueuedNotifications $job): bool => $job->deleteWhenMissingModels));
    }
}
