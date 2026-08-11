<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline\Listeners;

use App\Features\Timeline\Actions\CreateReply;
use App\Features\Timeline\Events\TimelineReplyPosted;
use App\Listeners\Timeline\NotifyTimelineReplyPosted;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\CommentReason;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelineMentionedNotification;
use App\Notifications\Timeline\TimelineRepliedNotification;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who a reply notifies: the thread root's author (Reply) and the root's other repliers (Related),
 * never the replier, never a member the reply already @mentions.
 */
class NotifyTimelineReplyPostedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_the_root_author_as_a_reply(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentTo(
            $owner,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n, array $channels) => $n->reason === CommentReason::Reply
                && $n->replier->is($replier)
                && $channels === ['mail', 'database'],
        );
    }

    public function test_notifies_a_co_replier_as_related_and_never_the_replier(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        $root = $this->root($owner);
        $this->seedReply($root, $earlier);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentTo(
            $earlier,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n) => $n->reason === CommentReason::Related,
        );
        Notification::assertNotSentTo($replier, TimelineRepliedNotification::class);
    }

    public function test_the_root_author_who_also_replied_gets_one_reply_notification(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $root = $this->root($owner);
        $this->seedReply($root, $owner); // the author joined their own thread
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentToTimes($owner, TimelineRepliedNotification::class, 1);
        Notification::assertSentTo(
            $owner,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n) => $n->reason === CommentReason::Reply,
        );
    }

    public function test_two_replies_from_the_same_member_notify_them_once(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        $root = $this->root($owner);
        $this->seedReply($root, $earlier);
        $this->seedReply($root, $earlier);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentToTimes($earlier, TimelineRepliedNotification::class, 1);
    }

    public function test_a_self_reply_notifies_nobody(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $owner);

        $this->handle($reply, $owner);

        Notification::assertNothingSent();
    }

    public function test_a_mentioned_member_is_not_told_twice(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        $root = $this->root($owner);
        $this->seedReply($root, $earlier);
        $reply = $this->seedReply($root, $replier);

        // Precedence Mention > Reply > Related: both would otherwise get a reply notification.
        $this->handle($reply, $replier, [(int) $owner->getKey(), (int) $earlier->getKey()]);

        Notification::assertNotSentTo($owner, TimelineRepliedNotification::class);
        Notification::assertNotSentTo($earlier, TimelineRepliedNotification::class);
    }

    public function test_skips_recipients_the_replier_is_blocked_against(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $replier->getKey()]);
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertNotSentTo($owner, TimelineRepliedNotification::class);
    }

    public function test_skips_a_banned_recipient(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $owner->forceFill(['is_login_rejected' => true])->save();
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertNotSentTo($owner, TimelineRepliedNotification::class);
    }

    public function test_skips_a_co_replier_who_can_no_longer_view_the_thread(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        // A friends-only thread: the co-replier is a stranger to the root's author now.
        $root = $this->root($owner, Visibility::Friends);
        $this->seedReply($root, $earlier);
        $this->makeFriends($owner, $replier);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertNotSentTo($earlier, TimelineRepliedNotification::class);
        Notification::assertSentTo($owner, TimelineRepliedNotification::class);
    }

    public function test_a_co_repliers_view_is_judged_against_the_thread_root(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        // The co-replier is the replier's friend but a stranger to the root's author, and a thread
        // is one audience — the root author's. Judged on the reply row they would pass.
        $this->makeFriends($replier, $earlier);
        $root = $this->root($owner, Visibility::Friends);
        $this->seedReply($root, $earlier);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertNotSentTo($earlier, TimelineRepliedNotification::class);
    }

    public function test_reply_opt_out_drops_mail_but_keeps_the_feed_row(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $owner->setNotificationSetting(NotificationKind::TimelineReplyPost, NotificationChannel::Mail, false);
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentTo(
            $owner,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_related_opt_out_drops_the_feed_row_but_keeps_mail(): void
    {
        Notification::fake();
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        $earlier->setNotificationSetting(NotificationKind::TimelineRelatedPost, NotificationChannel::Web, false);
        $root = $this->root($owner);
        $this->seedReply($root, $earlier);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentTo(
            $earlier,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n, array $channels) => $channels === ['mail'],
        );
    }

    public function test_the_admin_template_switch_keeps_the_feed_row(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        DB::table('mail_templates')->insert(['key' => MailTemplate::TimelinePostingNotified->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        Notification::assertSentTo(
            $owner,
            TimelineRepliedNotification::class,
            fn (TimelineRepliedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_feed_row_carries_the_replier_the_reason_and_the_reply(): void
    {
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $root = $this->root($owner);
        $reply = $this->seedReply($root, $replier);

        $this->handle($reply, $replier);

        $row = $owner->notifications()->sole();
        $this->assertSame(TimelineRepliedNotification::class, $row->type);
        $this->assertSame([
            'kind' => 'timeline_replied',
            'reason' => 'reply',
            'replier_id' => $replier->getKey(),
            'post_id' => $reply->getKey(),
        ], $row->data);
    }

    public function test_the_create_reply_action_dispatches_through_auto_discovery(): void
    {
        Notification::fake();
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $root = $this->root($owner);

        app(CreateReply::class)($replier, $root, 'hello');

        Notification::assertSentTo($owner, TimelineRepliedNotification::class);
    }

    public function test_a_reply_mentioning_the_root_author_sends_only_the_mention(): void
    {
        Notification::fake();
        $owner = Member::factory()->create(['name' => 'Owner']);
        $replier = Member::factory()->create();
        $root = $this->root($owner);
        $body = 'hi @Owner';

        app(CreateReply::class)($replier, $root, $body, [
            ['member_id' => $owner->getKey(), 'offset' => 3, 'length' => mb_strlen('@Owner')],
        ]);

        Notification::assertSentTo($owner, TimelineMentionedNotification::class);
        Notification::assertNotSentTo($owner, TimelineRepliedNotification::class);
    }

    public function test_should_send_drops_a_block_landing_while_queued(): void
    {
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $reply = $this->seedReply($this->root($owner), $replier);
        $notification = new TimelineRepliedNotification($replier, $reply, CommentReason::Reply);

        $this->assertTrue($notification->shouldSend($owner->fresh(), 'mail'));

        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $replier->getKey()]);

        $this->assertFalse($notification->shouldSend($owner->fresh(), 'mail'));
    }

    public function test_should_send_drops_a_ban_landing_while_queued(): void
    {
        [$owner, $replier] = Member::factory()->count(2)->create()->all();
        $reply = $this->seedReply($this->root($owner), $replier);
        $notification = new TimelineRepliedNotification($replier, $reply, CommentReason::Reply);

        $this->assertTrue($notification->shouldSend($owner->fresh(), 'database'));

        $owner->forceFill(['is_login_rejected' => true])->save();

        $this->assertFalse($notification->shouldSend($owner->fresh(), 'database'));
    }

    public function test_should_send_drops_a_co_replier_unfriended_from_a_friends_thread_while_queued(): void
    {
        [$owner, $earlier, $replier] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($owner, $earlier);
        $root = $this->root($owner, Visibility::Friends);
        $reply = $this->seedReply($root, $replier);
        $notification = new TimelineRepliedNotification($replier, $reply, CommentReason::Related);

        $this->assertTrue($notification->shouldSend($earlier->fresh(), 'mail'));

        DB::table('friendships')->delete();

        // Eligibility is judged on the thread root, whose audience the co-replier just left.
        $this->assertFalse($notification->shouldSend($earlier->fresh(), 'mail'));
    }

    /** @param  list<int>  $mentionedMemberIds */
    private function handle(TimelinePost $reply, Member $replier, array $mentionedMemberIds = []): void
    {
        app(NotifyTimelineReplyPosted::class)->handle(new TimelineReplyPosted($reply, $replier, $mentionedMemberIds));
    }

    private function root(Member $owner, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => $owner->getKey(), 'visibility' => $visibility]);
    }

    /** Seeds a reply row directly — the action would dispatch the event a second time. */
    private function seedReply(TimelinePost $root, Member $author): TimelinePost
    {
        return TimelinePost::factory()->replyTo($root)->create(['member_id' => $author->getKey()]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
