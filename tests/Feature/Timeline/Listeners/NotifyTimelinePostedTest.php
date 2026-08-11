<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline\Listeners;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\Queries\TimelinePostedRecipients;
use App\Jobs\BroadcastTimelinePosted;
use App\Listeners\Timeline\NotifyTimelinePosted;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Timeline\TimelinePostedNotification;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotifyTimelinePostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listener_dispatches_the_fan_out_job_with_the_mention_snapshot(): void
    {
        Bus::fake([BroadcastTimelinePosted::class]);
        $author = Member::factory()->create();
        $alice = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyTimelinePosted::class)->handle(
            new TimelinePostPosted($post, $author, [(int) $alice->getKey()]),
        );

        Bus::assertDispatched(
            BroadcastTimelinePosted::class,
            fn (BroadcastTimelinePosted $job) => $job->postId === (int) $post->getKey()
                && $job->mentionedMemberIds === [(int) $alice->getKey()],
        );
    }

    public function test_posting_dispatches_the_event(): void
    {
        Event::fake([TimelinePostPosted::class]);
        $author = Member::factory()->create();

        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('Body', Visibility::Members));

        Event::assertDispatched(
            TimelinePostPosted::class,
            fn (TimelinePostPosted $event) => $event->post->is($post) && $event->author->is($author),
        );
    }

    public function test_a_reply_does_not_broadcast(): void
    {
        // Only TimelinePostPosted reaches this listener, and a reply dispatches TimelineReplyPosted.
        Bus::fake([BroadcastTimelinePosted::class]);
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $root = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);

        app(CreateReply::class)($replier, $root, 'hello');

        Bus::assertNotDispatched(BroadcastTimelinePosted::class);
    }

    public function test_should_send_drops_a_block_landing_while_queued(): void
    {
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $notification = new TimelinePostedNotification($post, $author, ['mail']);

        $this->assertTrue($notification->shouldSend($reader->fresh(), 'mail'));

        DB::table('member_blocks')->insert(['blocker_id' => $reader->getKey(), 'blocked_id' => $author->getKey()]);

        $this->assertFalse($notification->shouldSend($reader->fresh(), 'mail'));
    }

    public function test_should_send_drops_a_ban_landing_while_queued(): void
    {
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $notification = new TimelinePostedNotification($post, $author, ['database']);

        $this->assertTrue($notification->shouldSend($reader->fresh(), 'database'));

        $reader->forceFill(['is_login_rejected' => true])->save();

        $this->assertFalse($notification->shouldSend($reader->fresh(), 'database'));
    }

    public function test_should_send_drops_a_friends_post_recipient_unfriended_while_queued(): void
    {
        [$author, $friend] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $author->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $author->getKey()],
        ]);
        $post = TimelinePost::factory()->friends()->create(['member_id' => $author->getKey()]);
        $notification = new TimelinePostedNotification($post, $author, ['mail']);

        $this->assertTrue($notification->shouldSend($friend->fresh(), 'mail'));

        DB::table('friendships')->delete();

        // The mail would carry the post body to someone no longer in the audience.
        $this->assertFalse($notification->shouldSend($friend->fresh(), 'mail'));
    }

    public function test_posting_reaches_the_audience_through_the_queue(): void
    {
        config(['queue.default' => 'database']);
        [$author, $reader] = Member::factory()->count(2)->create()->all();

        app(CreateTimelinePost::class)($author, new TimelinePostFormData('Body', Visibility::Members));

        // Two waves: the fan-out job, then the notification job it queues.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $reader->getKey(),
            'type' => TimelinePostedNotification::class,
        ]);
    }

    public function test_a_queued_delivery_re_checks_before_sending(): void
    {
        config(['queue.default' => 'database']);
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        // The fan-out runs first, so the block below lands between enqueue and delivery — past the
        // audience query, which is exactly the gap shouldSend() exists to close.
        $this->fanOut($post);
        $this->assertGreaterThan(0, $this->queuedNotificationJobs());

        DB::table('member_blocks')->insert(['blocker_id' => $reader->getKey(), 'blocked_id' => $author->getKey()]);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $reader->getKey()]);
    }

    public function test_a_queued_delivery_sends_when_still_eligible(): void
    {
        config(['queue.default' => 'database']);
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $this->fanOut($post);
        $this->assertGreaterThan(0, $this->queuedNotificationJobs());

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $reader->getKey()]);
    }

    private function fanOut(TimelinePost $post): void
    {
        (new BroadcastTimelinePosted((int) $post->getKey()))
            ->handle(app(TimelinePostedRecipients::class), app(MailTemplateService::class));
    }

    private function queuedNotificationJobs(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendQueuedNotifications%')->count();
    }
}
