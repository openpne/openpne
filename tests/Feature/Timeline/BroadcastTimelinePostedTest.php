<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use App\Features\Timeline\Queries\TimelinePostedRecipients;
use App\Jobs\BroadcastTimelinePosted;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelinePostedNotification;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The new-post broadcast: audience by visibility (Open/Members = everyone, Friends = the author's
 * friends, Private = nobody), minus the author / banned / blocked / already mentioned; each
 * recipient's channels are the OpenPNE 3 union of timeline-new-post and (for friends)
 * timeline-new-post-only-friends, absent-means-on.
 */
class BroadcastTimelinePostedTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<int>  $mentionedMemberIds */
    private function broadcast(TimelinePost $post, array $mentionedMemberIds = []): void
    {
        (new BroadcastTimelinePosted((int) $post->getKey(), $mentionedMemberIds))
            ->handle(app(TimelinePostedRecipients::class), app(MailTemplateService::class));
    }

    private function newPost(Member $author, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => $visibility]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    public function test_a_members_post_notifies_every_other_active_member(): void
    {
        Notification::fake();
        [$author, $reader, $other] = Member::factory()->count(3)->create()->all();
        $post = $this->newPost($author);

        $this->broadcast($post);

        foreach ([$reader, $other] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                TimelinePostedNotification::class,
                fn (TimelinePostedNotification $n, array $channels) => $n->post->is($post)
                    && $channels === ['mail', 'database'],
            );
        }
        Notification::assertNotSentTo($author, TimelinePostedNotification::class);
    }

    public function test_a_web_public_post_notifies_every_other_active_member(): void
    {
        Notification::fake();
        [$author, $reader] = Member::factory()->count(2)->create()->all();

        $this->broadcast($this->newPost($author, Visibility::Open));

        Notification::assertSentTo($reader, TimelinePostedNotification::class);
    }

    public function test_a_friends_post_notifies_only_the_authors_friends(): void
    {
        Notification::fake();
        [$author, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        $this->makeFriends($author, $friend);

        $this->broadcast($this->newPost($author, Visibility::Friends));

        Notification::assertSentTo($friend, TimelinePostedNotification::class);
        Notification::assertNotSentTo($stranger, TimelinePostedNotification::class);
    }

    public function test_a_private_post_notifies_nobody(): void
    {
        Notification::fake();
        [$author] = Member::factory()->count(2)->create()->all();

        $this->broadcast($this->newPost($author, Visibility::Private));

        Notification::assertNothingSent();
    }

    public function test_banned_and_blocked_members_are_excluded(): void
    {
        Notification::fake();
        [$author, $banned, $blocked] = Member::factory()->count(3)->create()->all();
        $banned->forceFill(['is_login_rejected' => true])->save();
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $author->getKey()]);

        $this->broadcast($this->newPost($author));

        Notification::assertNotSentTo($banned, TimelinePostedNotification::class);
        Notification::assertNotSentTo($blocked, TimelinePostedNotification::class);
    }

    public function test_a_mentioned_member_is_not_told_twice(): void
    {
        Notification::fake();
        [$author, $mentioned, $other] = Member::factory()->count(3)->create()->all();

        // The mention notification already reaches them; precedence is Mention > NewPost.
        $this->broadcast($this->newPost($author), [(int) $mentioned->getKey()]);

        Notification::assertNotSentTo($mentioned, TimelinePostedNotification::class);
        Notification::assertSentTo($other, TimelinePostedNotification::class);
    }

    public function test_opting_out_of_the_broad_kind_drops_the_channel(): void
    {
        Notification::fake();
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $reader->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Mail, false);

        $this->broadcast($this->newPost($author));

        // Not a friend, so the friends-only variant cannot re-add mail — database only remains.
        Notification::assertSentTo(
            $reader,
            TimelinePostedNotification::class,
            fn (TimelinePostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_friends_only_variant_still_reaches_a_friend_who_turned_off_the_broad_kind(): void
    {
        Notification::fake();
        [$author, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($author, $friend);
        $friend->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Mail, false);
        $friend->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Web, false);

        $this->broadcast($this->newPost($author));

        Notification::assertSentTo(
            $friend,
            TimelinePostedNotification::class,
            fn (TimelinePostedNotification $n, array $channels) => $channels === ['mail', 'database'],
        );
    }

    public function test_the_friends_only_variant_does_not_reach_a_stranger(): void
    {
        Notification::fake();
        [$author, $stranger] = Member::factory()->count(2)->create()->all();
        $stranger->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Mail, false);
        $stranger->setNotificationSetting(NotificationKind::TimelineNewPost, NotificationChannel::Web, false);

        $this->broadcast($this->newPost($author));

        Notification::assertNotSentTo($stranger, TimelinePostedNotification::class);
    }

    public function test_both_kinds_off_drops_a_friend_entirely(): void
    {
        Notification::fake();
        [$author, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($author, $friend);
        foreach ([NotificationKind::TimelineNewPost, NotificationKind::TimelineNewPostOnlyFriends] as $kind) {
            foreach ([NotificationChannel::Mail, NotificationChannel::Web] as $channel) {
                $friend->setNotificationSetting($kind, $channel, false);
            }
        }

        $this->broadcast($this->newPost($author));

        Notification::assertNotSentTo($friend, TimelinePostedNotification::class);
    }

    public function test_a_member_without_an_address_gets_the_feed_row_but_no_mail(): void
    {
        Notification::fake();
        $author = Member::factory()->create();
        $noAddress = Member::factory()->create(['email' => null]);

        $this->broadcast($this->newPost($author));

        Notification::assertSentTo(
            $noAddress,
            TimelinePostedNotification::class,
            fn (TimelinePostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_admin_template_switch_keeps_the_feed_row(): void
    {
        Notification::fake();
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        DB::table('mail_templates')->insert(['key' => MailTemplate::TimelinePostingNotified->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();

        $this->broadcast($this->newPost($author));

        Notification::assertSentTo(
            $reader,
            TimelinePostedNotification::class,
            fn (TimelinePostedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_the_feed_row_carries_the_author_and_the_post(): void
    {
        [$author, $reader] = Member::factory()->count(2)->create()->all();
        $post = $this->newPost($author);

        $this->broadcast($post);

        $row = $reader->notifications()->sole();
        $this->assertSame(TimelinePostedNotification::class, $row->type);
        $this->assertSame([
            'kind' => 'timeline_posted',
            'author_id' => $author->getKey(),
            'post_id' => $post->getKey(),
        ], $row->data);
    }
}
