<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Actions\MarkTalkRead;
use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Features\GroupTalk\Queries\GroupTalkBroadcastRecipients;
use App\Features\GroupTalk\TalkReadCursor;
use App\Features\GroupTopic\TopicReadAccess;
use App\Jobs\BroadcastGroupMessagePosted;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The per-message broadcast a site turns on with `group_talk_notify_default = all`. A mentions-only
 * site must stay as cheap as it was; an `all` site reaches every unmuted member who has not already
 * read the message, and the room keeps exactly one feed row however much is said in it.
 */
class TalkNewMessageNotificationTest extends TalkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Self-contained mailer: sentMailCount() reads the array transport's buffer, and the
        // environment's MAIL_MAILER must not decide whether that exists.
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
    }

    private function notifyEveryMessage(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);
    }

    private function joined(Group $group, string $name = 'Bob'): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $member;
    }

    /** A message written straight into the room, so the broadcast can be run deliberately. */
    private function message(Group $group, Member $author, string $body = 'anyone about?'): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => $body,
        ]);
    }

    /** @param list<int> $mentionedMemberIds */
    private function broadcast(GroupMessage $message, array $mentionedMemberIds = []): void
    {
        (new BroadcastGroupMessagePosted((int) $message->getKey(), $mentionedMemberIds))
            ->handle(app(GroupTalkBroadcastRecipients::class), app(MailTemplateService::class));
    }

    private function mute(Group $group, Member $member): void
    {
        DB::table('group_members')
            ->where('group_id', $group->getKey())->where('member_id', $member->getKey())
            ->update(['is_talk_muted' => true]);
    }

    private function readUpTo(Member $member, GroupMessage $message): void
    {
        TalkReadCursor::advance(
            (int) $message->group_id,
            (int) $member->getKey(),
            CarbonImmutable::instance($message->created_at),
            (int) $message->getKey(),
        );
    }

    /** @return Collection<int, DatabaseNotification> */
    private function roomRows(Member $member, Group $group)
    {
        return app(GroupTalkRoomNotificationRows::class)->rowsFor((int) $member->getKey(), (int) $group->getKey());
    }

    /** Mails that reached the transport (mail.default=array), which Mail::fake() would not see. */
    private function sentMailCount(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    public function test_a_mentions_only_site_with_nobody_opted_in_never_walks_the_room(): void
    {
        Notification::fake();
        $group = $this->group();
        $author = $this->memberOf($group);
        $this->joined($group);
        $message = $this->message($group, $author);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->broadcast($message);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        Notification::assertNothingSent();
        $this->assertNotEmpty($queries, 'the job must have run — an empty log would pass the check below for free');
        $this->assertTrue(
            $queries->filter(fn (string $query): bool => str_contains($query, 'group_members'))->isEmpty(),
            'a mentions-only site must exit before the audience query',
        );
    }

    public function test_a_member_who_opted_in_is_reached_on_a_mentions_only_site(): void
    {
        Notification::fake();
        $group = $this->group();
        $author = $this->memberOf($group);
        $optedIn = $this->joined($group);
        $bystander = $this->joined($group, 'Carol');
        $optedIn->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, true);

        $this->broadcast($this->message($group, $author));

        Notification::assertSentTo(
            $optedIn,
            GroupTalkMessagePostedNotification::class,
            fn (GroupTalkMessagePostedNotification $n, array $channels): bool => $channels === ['database'],
        );
        Notification::assertNotSentTo($bystander, GroupTalkMessagePostedNotification::class);
    }

    public function test_every_unmuted_member_is_reached_when_the_site_notifies_of_every_message(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $other = $this->joined($group, 'Carol');

        $this->broadcast($this->message($group, $author));

        foreach ([$reader, $other] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                GroupTalkMessagePostedNotification::class,
                // Mail is off by default however loud the site is, so the row is all they get.
                fn (GroupTalkMessagePostedNotification $n, array $channels): bool => $channels === ['database'],
            );
        }
        Notification::assertNotSentTo($author, GroupTalkMessagePostedNotification::class);
    }

    public function test_a_mentioned_member_is_not_told_twice(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $mentioned = $this->joined($group);
        $other = $this->joined($group, 'Carol');

        $this->broadcast($this->message($group, $author), [(int) $mentioned->getKey()]);

        Notification::assertNotSentTo($mentioned, GroupTalkMessagePostedNotification::class);
        Notification::assertSentTo($other, GroupTalkMessagePostedNotification::class);
    }

    /** The asymmetry with a mention: asking the room for quiet answers a message addressed to the room. */
    public function test_a_muted_member_hears_nothing(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $muted = $this->joined($group);
        $this->mute($group, $muted);

        $this->broadcast($this->message($group, $author));

        Notification::assertNotSentTo($muted, GroupTalkMessagePostedNotification::class);
    }

    /**
     * The audience query itself, not just the delivery gate: a quiet room is the common case on an
     * `all` site, so mute must drop out in SQL rather than be walked and discarded.
     */
    public function test_the_audience_query_leaves_out_a_muted_member(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $muted = $this->joined($group);
        $listening = $this->joined($group, 'Carol');
        $this->mute($group, $muted);

        $audience = app(GroupTalkBroadcastRecipients::class)->viewers($group, $author)->pluck('id')->all();

        $this->assertSame([$listening->getKey()], $audience);
    }

    public function test_banned_and_blocked_members_are_excluded(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $banned = $this->joined($group);
        $blocked = $this->joined($group, 'Carol');
        $banned->forceFill(['is_login_rejected' => true])->save();
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $author->getKey()]);

        $this->broadcast($this->message($group, $author));

        Notification::assertNotSentTo($banned, GroupTalkMessagePostedNotification::class);
        Notification::assertNotSentTo($blocked, GroupTalkMessagePostedNotification::class);
    }

    /** The grace the dispatch waits out: someone sitting in the room has already seen it. */
    public function test_a_member_who_already_read_the_message_is_not_told_about_it(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $behind = $this->joined($group, 'Carol');
        $message = $this->message($group, $author);
        $this->readUpTo($reader, $message);

        $this->broadcast($message);

        Notification::assertNotSentTo($reader, GroupTalkMessagePostedNotification::class);
        Notification::assertSentTo($behind, GroupTalkMessagePostedNotification::class);
    }

    public function test_an_explicit_off_row_wins_over_a_loud_site(): void
    {
        Notification::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $quiet = $this->joined($group);
        $quiet->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Web, false);

        $this->broadcast($this->message($group, $author));

        Notification::assertNotSentTo($quiet, GroupTalkMessagePostedNotification::class);
    }

    public function test_mail_needs_an_explicit_opt_in_and_an_address(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $byMail = $this->joined($group);
        $byMail->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Mail, true);
        $silent = $this->joined($group, 'Carol');
        // Opted in but login-impossible: the feed row still lands, the mail cannot.
        $noAddress = $this->joined($group, 'Dave');
        $noAddress->forceFill(['email' => null])->save();
        $noAddress->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Mail, true);

        $this->broadcast($this->message($group, $author));

        $this->assertSame(1, $this->sentMailCount());
        foreach ([$byMail, $silent, $noAddress] as $member) {
            $this->assertCount(1, $this->roomRows($member, $group));
        }
    }

    public function test_the_admin_template_switch_keeps_the_feed_row(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $byMail = $this->joined($group);
        $byMail->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Mail, true);
        DB::table('mail_templates')->insert(['key' => MailTemplate::GroupTalkMessageNotified->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();

        $this->broadcast($this->message($group, $author));

        $this->assertSame(0, $this->sentMailCount());
        $this->assertCount(1, $this->roomRows($byMail, $group));
    }

    /** An AI account keeps the record of what happened to it, and has nobody to interrupt by mail. */
    public function test_an_ai_account_gets_the_row_but_no_mail(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $ai = Member::factory()->aiAccount()->create(['name' => 'Assistant']);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $ai->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ai->setNotificationSetting(NotificationKind::GroupTalkNewMessage, NotificationChannel::Mail, true);

        $this->broadcast($this->message($group, $author));

        $this->assertCount(1, $this->roomRows($ai, $group));
        $this->assertSame(0, $this->sentMailCount());
    }

    /** @return array<string, array{0: string}> */
    public static function changedAfterEnqueue(): array
    {
        return [
            'muted the room' => ['mute'],
            'left the group' => ['leave'],
            'read the message' => ['read'],
        ];
    }

    #[DataProvider('changedAfterEnqueue')]
    public function test_should_send_re_evaluates_at_delivery_time(string $change): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group);
        $message = $this->message($group, $author);
        $notification = new GroupTalkMessagePostedNotification($author, $message, ['database']);

        $this->assertTrue($notification->shouldSend($target, 'database'));

        match ($change) {
            'mute' => $this->mute($group, $target),
            'leave' => DB::table('group_members')->where('member_id', $target->getKey())->delete(),
            'read' => $this->readUpTo($target, $message),
        };

        $this->assertFalse($notification->shouldSend($target->fresh(), 'database'));
    }

    public function test_the_feed_row_carries_the_author_the_group_and_the_message(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $message = $this->message($group, $author);

        $this->broadcast($message);

        $row = $this->roomRows($reader, $group)->sole();
        $this->assertSame(GroupTalkMessagePostedNotification::class, $row->type);
        $this->assertSame([
            'kind' => 'group_talk_new_message',
            'author_id' => $author->getKey(),
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
        ], $row->data);
    }

    /**
     * The room's row, not the message's: three messages leave one row, read rows included — while
     * still sending three times, so the device is nudged for each (push follows the database send).
     */
    public function test_a_busy_room_leaves_one_row_and_still_sends_each_time(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);

        $sends = 0;
        Event::listen(function (NotificationSent $event) use (&$sends): void {
            if ($event->channel === 'database' && $event->notification instanceof GroupTalkMessagePostedNotification) {
                $sends++;
            }
        });

        $this->broadcast($this->message($group, $author, 'one'));
        // Read in between: the replaced row goes whether or not it was read.
        app(GroupTalkRoomNotificationRows::class)->markRead((int) $reader->getKey(), (int) $group->getKey());
        $this->broadcast($this->message($group, $author, 'two'));
        $this->broadcast($this->message($group, $author, 'three'));

        $this->assertSame(3, $sends);
        $rows = $this->roomRows($reader, $group);
        $this->assertCount(1, $rows);
        $this->assertSame('three', GroupMessage::find($rows->first()->data['message_id'])->body);
    }

    public function test_a_second_room_keeps_its_own_row(): void
    {
        $this->notifyEveryMessage();
        $reader = Member::factory()->create();
        $rooms = [];
        foreach (['a', 'b'] as $name) {
            $group = $this->group();
            $author = $this->memberOf($group);
            DB::table('group_members')->insert([
                'group_id' => $group->getKey(), 'member_id' => $reader->getKey(), 'role' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->broadcast($this->message($group, $author, $name));
            $rooms[] = $group;
        }

        foreach ($rooms as $group) {
            $this->assertCount(1, $this->roomRows($reader, $group));
        }
    }

    public function test_a_mark_all_read_leaves_the_next_message_a_row_of_its_own(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);

        $this->broadcast($this->message($group, $author, 'one'));
        $this->actingAs($reader)->from('/notifications')->post('/notifications/read-all');
        $this->broadcast($this->message($group, $author, 'two'));

        $rows = $this->roomRows($reader, $group);
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->read_at);
    }

    /** @return array<string, array{0: string}> */
    public static function waysToReadTheRoom(): array
    {
        return [
            'the page reports what it rendered' => ['http'],
            'the digest catch-up' => ['catchUp'],
            'the mark-read action (what MCP calls)' => ['action'],
            'a read whose cursor does not move' => ['alreadyCurrent'],
            'posting into the room' => ['post'],
        ];
    }

    #[DataProvider('waysToReadTheRoom')]
    public function test_reading_the_room_marks_its_row_read(string $way): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $message = $this->message($group, $author);
        $this->broadcast($message);

        $this->assertNull($this->roomRows($reader, $group)->sole()->read_at);

        match ($way) {
            'http' => $this->actingAs($reader)
                ->postJson("/groups/{$group->getKey()}/talk/read", ['message_id' => $message->getKey()])
                ->assertNoContent(),
            'catchUp' => $this->actingAs($reader)
                ->postJson("/groups/{$group->getKey()}/talk/read", [])
                ->assertNoContent(),
            'action' => app(MarkTalkRead::class)($reader, $group, (int) $message->getKey()),
            'alreadyCurrent' => (function () use ($reader, $group, $message): void {
                // Cursor already past it, so the read moves nothing — the row must still be marked.
                $this->readUpTo($reader, $message);
                app(MarkTalkRead::class)($reader, $group, (int) $message->getKey());
            })(),
            'post' => app(CreateGroupMessage::class)($reader, $group, 'me too'),
        };

        $this->assertNotNull($this->roomRows($reader, $group)->sole()->read_at);
    }

    public function test_the_feed_row_opens_the_room(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $this->broadcast($this->message($group, $author));
        $row = $this->roomRows($reader, $group)->sole();

        // The room, with no ?m=: talk opens on the unread boundary, which is where the reader wants
        // to arrive.
        $this->actingAs($reader)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect("/groups/{$group->getKey()}/talk");
    }

    public function test_the_feed_row_falls_back_when_the_room_is_no_longer_readable(): void
    {
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);
        $this->broadcast($this->message($group, $author));
        $row = $this->roomRows($reader, $group)->sole();

        $group->forceFill(['topic_read_access' => TopicReadAccess::MembersOnly])->save();
        DB::table('group_members')->where('member_id', $reader->getKey())->delete();

        $this->actingAs($reader)->post("/notifications/{$row->getKey()}/open")
            ->assertRedirect(route('notifications.index'));
    }

    /**
     * The replace runs inside the queued job that wrote the row, so a failure there must be reported
     * and dropped: rethrowing would retry the job and duplicate the row it exists to deduplicate.
     */
    public function test_a_failing_replace_is_reported_and_leaves_the_new_row_intact(): void
    {
        Exceptions::fake();
        $this->notifyEveryMessage();
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->joined($group);

        $rows = app(GroupTalkRoomNotificationRows::class);
        $this->app->instance(GroupTalkRoomNotificationRows::class, new class extends GroupTalkRoomNotificationRows
        {
            public function deleteOthers(int $memberId, int $groupId, string $keepId): void
            {
                throw new RuntimeException('the feed is unavailable');
            }
        });

        $this->broadcast($this->message($group, $author));

        Exceptions::assertReported(RuntimeException::class);
        $this->assertCount(1, $rows->rowsFor((int) $reader->getKey(), (int) $group->getKey()));
    }
}
