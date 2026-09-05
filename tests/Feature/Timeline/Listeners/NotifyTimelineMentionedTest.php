<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline\Listeners;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Listeners\Timeline\NotifyTimelineMentioned;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelineMentionedNotification;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The delivery-time half, which re-checks what the interval can have changed; storage settled
 * mentionability at write time (Tests\Feature\Timeline\MentionStorageTest).
 */
class NotifyTimelineMentionedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mentioned_member_is_notified_on_both_channels(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $post = $this->createPost($author, 'hi @Alice', [$alice]);

        Notification::assertSentTo(
            $alice,
            TimelineMentionedNotification::class,
            fn (TimelineMentionedNotification $notification, array $channels): bool => $notification->author->is($author)
                && $notification->post->is($post)
                && $channels === ['mail', 'database'],
        );
    }

    public function test_the_feed_row_carries_the_author_and_the_mentioning_post(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $post = $this->createPost($author, 'hi @Alice', [$alice]);

        $row = $alice->notifications()->sole();
        $this->assertSame(TimelineMentionedNotification::class, $row->type);
        $this->assertSame([
            'kind' => 'timeline_mentioned',
            'author_id' => $author->getKey(),
            'post_id' => $post->getKey(),
        ], $row->data);
    }

    public function test_two_mentions_of_the_same_member_send_one_notification(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->createPost($author, 'hi @Alice and again @Alice', [$alice, $alice]);

        Notification::assertSentToTimes($alice, TimelineMentionedNotification::class, 1);
    }

    public function test_the_snapshot_names_each_mentioned_member_once(): void
    {
        // The event's snapshot is the audience later fan-outs subtract, so its distinctness is the
        // contract, not an accident of how the recipients are looked up.
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $ids = null;

        Notification::fake();
        $this->app['events']->listen(TimelinePostPosted::class, function (TimelinePostPosted $event) use (&$ids): void {
            $ids = $event->mentionedMemberIds;
        });

        $this->createPost($author, 'hi @Alice and again @Alice', [$alice, $alice]);

        $this->assertSame([$alice->getKey()], $ids);
    }

    public function test_the_author_is_never_notified(): void
    {
        Notification::fake();
        $author = Member::factory()->create(['name' => 'Author']);
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->createPost($author, 'hi @Alice', [$alice]);

        // The snapshot cannot hold the author (storage drops a self-mention), so the listener is
        // handed one that does.
        $this->handlePost($post, $author, [$author->getKey(), $alice->getKey()]);

        Notification::assertNotSentTo($author, TimelineMentionedNotification::class);
    }

    public function test_a_private_post_does_not_reach_a_mentioned_stranger(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->createPost($author, 'hi @Alice', [$alice], Visibility::Private);

        Notification::assertNotSentTo($alice, TimelineMentionedNotification::class);
    }

    public function test_a_reply_mention_is_judged_against_the_thread_root(): void
    {
        Notification::fake();
        [$rootAuthor, $replier] = Member::factory()->count(2)->create()->all();
        $alice = Member::factory()->create(['name' => 'Alice']);
        // Alice is the replier's friend but a stranger to the root's author, and judged on the reply
        // row — whose own owner is the replier — she would pass.
        $this->makeFriends($replier, $alice);
        $root = TimelinePost::factory()->friends()->create(['member_id' => $rootAuthor->getKey()]);

        $this->reply($replier, $root, 'hi @Alice', [$alice]);

        Notification::assertNotSentTo($alice, TimelineMentionedNotification::class);
    }

    public function test_a_reply_mention_reaches_the_root_authors_friend(): void
    {
        Notification::fake();
        [$rootAuthor, $replier] = Member::factory()->count(2)->create()->all();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $this->makeFriends($rootAuthor, $alice);
        $root = TimelinePost::factory()->friends()->create(['member_id' => $rootAuthor->getKey()]);

        $reply = $this->reply($replier, $root, 'hi @Alice', [$alice]);

        Notification::assertSentTo(
            $alice,
            TimelineMentionedNotification::class,
            fn (TimelineMentionedNotification $notification): bool => $notification->post->is($reply),
        );
    }

    public function test_a_block_placed_after_the_post_drops_the_delivery(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->createPost($author, 'hi @Alice', [$alice]);

        $this->block($alice, $author);
        $this->handlePost($post, $author, [$alice->getKey()]);

        Notification::assertSentToTimes($alice, TimelineMentionedNotification::class, 1); // the first, pre-block send
    }

    public function test_a_banned_recipient_is_skipped(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->createPost($author, 'hi @Alice', [$alice]);

        $alice->forceFill(['is_login_rejected' => true])->save();
        $this->handlePost($post, $author, [$alice->getKey()]);

        Notification::assertSentToTimes($alice, TimelineMentionedNotification::class, 1);
    }

    public function test_should_send_drops_a_block_landing_while_queued(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->createPost($author, 'hi @Alice', [$alice]);
        $notification = new TimelineMentionedNotification($author, $post);

        $this->assertTrue($notification->shouldSend($alice->fresh(), 'mail'));

        $this->block($alice, $author);

        $this->assertFalse($notification->shouldSend($alice->fresh(), 'mail'));
    }

    public function test_should_send_drops_a_ban_landing_while_queued(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->createPost($author, 'hi @Alice', [$alice]);
        $notification = new TimelineMentionedNotification($author, $post);

        $this->assertTrue($notification->shouldSend($alice->fresh(), 'database'));

        $alice->forceFill(['is_login_rejected' => true])->save();

        $this->assertFalse($notification->shouldSend($alice->fresh(), 'database'));
    }

    public function test_should_send_drops_a_friends_thread_recipient_unfriended_while_queued(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $this->makeFriends($author, $alice);
        $post = $this->createPost($author, 'hi @Alice', [$alice], Visibility::Friends);
        $notification = new TimelineMentionedNotification($author, $post);

        $this->assertTrue($notification->shouldSend($alice->fresh(), 'mail'));

        DB::table('friendships')->delete();

        // The mail would carry the post body to someone no longer in the thread's audience.
        $this->assertFalse($notification->shouldSend($alice->fresh(), 'mail'));
    }

    public function test_a_queued_delivery_re_checks_before_sending(): void
    {
        config(['queue.default' => 'database']);
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->createPost($author, 'hi @Alice', [$alice]);
        $this->assertGreaterThan(0, $this->queuedNotificationJobs());

        $this->block($alice, $author);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $alice->getKey()]);
    }

    public function test_a_queued_delivery_sends_when_still_eligible(): void
    {
        config(['queue.default' => 'database']);
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->createPost($author, 'hi @Alice', [$alice]);
        $this->assertGreaterThan(0, $this->queuedNotificationJobs());

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024]);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $alice->getKey()]);
    }

    public function test_the_web_opt_out_drops_the_feed_row(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $alice->setNotificationSetting(NotificationKind::TimelineMention, NotificationChannel::Web, false);

        $this->createPost($author, 'hi @Alice', [$alice]);

        Notification::assertSentTo(
            $alice,
            TimelineMentionedNotification::class,
            fn (TimelineMentionedNotification $notification, array $channels): bool => $channels === ['mail'],
        );
    }

    public function test_the_mail_opt_out_keeps_the_feed_row(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $alice->setNotificationSetting(NotificationKind::TimelineMention, NotificationChannel::Mail, false);

        $this->createPost($author, 'hi @Alice', [$alice]);

        Notification::assertSentTo(
            $alice,
            TimelineMentionedNotification::class,
            fn (TimelineMentionedNotification $notification, array $channels): bool => $channels === ['database'],
        );
    }

    public function test_the_admin_template_switch_keeps_the_feed_row(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        DB::table('mail_templates')->insert(['key' => MailTemplate::TimelineMentionNotified->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();

        $this->createPost($author, 'hi @Alice', [$alice]);

        Notification::assertSentTo(
            $alice,
            TimelineMentionedNotification::class,
            fn (TimelineMentionedNotification $notification, array $channels): bool => $channels === ['database'],
        );
    }

    /** The queued notification sends — a post also queues housekeeping jobs (link-card sync). */
    private function queuedNotificationJobs(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendQueuedNotifications%')->count();
    }

    /** @param  list<Member>  $mentioned */
    private function createPost(Member $author, string $body, array $mentioned, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData($body, $visibility, $this->payload($body, $mentioned)),
        );
    }

    /** @param  list<Member>  $mentioned */
    private function reply(Member $author, TimelinePost $parent, string $body, array $mentioned): TimelinePost
    {
        return app(CreateReply::class)($author, $parent, $body, $this->payload($body, $mentioned));
    }

    /** @param  list<int>  $mentionedMemberIds */
    private function handlePost(TimelinePost $post, Member $author, array $mentionedMemberIds): void
    {
        app(NotifyTimelineMentioned::class)->handlePostPosted(new TimelinePostPosted($post, $author, $mentionedMemberIds));
    }

    /**
     * The payload rows a picker would send for each mention, in body order. Repeating a member
     * addresses their next occurrence, so the same handle can be picked twice.
     *
     * @param  list<Member>  $mentioned
     * @return list<array{member_id: int, offset: int, length: int}>
     */
    private function payload(string $body, array $mentioned): array
    {
        $payload = [];
        $from = 0;

        foreach ($mentioned as $member) {
            $handle = '@'.$member->name;
            $offset = mb_strpos($body, $handle, $from);
            $payload[] = ['member_id' => $member->getKey(), 'offset' => $offset, 'length' => mb_strlen($handle)];
            $from = $offset + mb_strlen($handle);
        }

        return $payload;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
            'created_at' => now(),
        ]);
    }
}
