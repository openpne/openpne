<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Features\GroupTopic\TopicReadAccess;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The one notification talk sends. Eligibility is asked twice — at enqueue and again in
 * shouldSend() before each channel — because a mention mail carries the message body and can outlive
 * the facts it was enqueued under.
 */
class TalkMentionNotificationTest extends TalkTestCase
{
    private function joined(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $member;
    }

    private function mention(Member $author, Group $group, Member $target): void
    {
        $this->actingAs($author)->postJson("/groups/{$group->getKey()}/talk", [
            'body' => 'hi @'.$target->name,
            'mentions' => [['member_id' => $target->getKey(), 'offset' => 3, 'length' => mb_strlen($target->name) + 1]],
        ])->assertCreated();
    }

    public function test_a_mentioned_member_is_notified(): void
    {
        Notification::fake();
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');

        $this->mention($author, $group, $target);

        Notification::assertSentTo($target, GroupTalkMentionedNotification::class);
    }

    public function test_nobody_is_notified_when_the_row_was_dropped(): void
    {
        Notification::fake();
        $group = $this->group();
        $author = $this->memberOf($group);
        $outsider = Member::factory()->create(['name' => 'Bob']);

        $this->mention($author, $group, $outsider);

        Notification::assertNothingSent();
    }

    /** A mention is addressed to one person; being named outranks having asked the room for quiet. */
    public function test_a_muted_recipient_is_still_notified(): void
    {
        Notification::fake();
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $this->actingAs($target)->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true])->assertNoContent();

        $this->mention($author, $group, $target);

        Notification::assertSentTo($target, GroupTalkMentionedNotification::class);
    }

    /** @return array<string, array{0: string}> */
    public static function ineligible(): array
    {
        return [
            'banned' => ['ban'],
            'blocked the author' => ['blockAuthor'],
            'blocked by the author' => ['blockedByAuthor'],
            'left the group' => ['leave'],
            'lost read access' => ['membersOnlyAndLeave'],
        ];
    }

    #[DataProvider('ineligible')]
    public function test_eligibility_refuses(string $situation): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');

        match ($situation) {
            'ban' => $target->forceFill(['is_login_rejected' => true])->save(),
            'blockAuthor' => DB::table('member_blocks')->insert(['blocker_id' => $target->getKey(), 'blocked_id' => $author->getKey()]),
            'blockedByAuthor' => DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $target->getKey()]),
            'leave' => DB::table('group_members')->where('member_id', $target->getKey())->delete(),
            'membersOnlyAndLeave' => (function () use ($group, $target) {
                $group->forceFill(['topic_read_access' => TopicReadAccess::MembersOnly])->save();
                DB::table('group_members')->where('member_id', $target->getKey())->delete();
            })(),
        };

        $this->assertFalse(GroupTalkNotificationEligibility::canReceive($target->fresh(), $group->fresh(), $author));
    }

    public function test_the_author_is_never_their_own_recipient(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->assertFalse(GroupTalkNotificationEligibility::canReceive($author, $group, $author));
    }

    /**
     * The delivery-time re-check. The notification is built while the recipient is eligible, then the
     * facts change before a channel runs — shouldSend must answer with the world as it is now.
     */
    public function test_should_send_re_evaluates_at_delivery_time(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $notification = new GroupTalkMentionedNotification($author, $message);

        $this->assertTrue($notification->shouldSend($target, 'mail'));

        DB::table('group_members')->where('member_id', $target->getKey())->delete();

        $this->assertFalse($notification->shouldSend($target->fresh(), 'mail'),
            'a member who left between enqueue and delivery must not receive the body');
    }

    public function test_should_send_refuses_while_the_unit_is_off(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $notification = new GroupTalkMentionedNotification($author, $message);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->assertFalse($notification->shouldSend($target, 'mail'));
    }

    /** An author gone before delivery takes the queued job with them rather than arriving anonymous. */
    public function test_the_notification_is_dropped_when_its_author_is_missing(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue((new GroupTalkMentionedNotification($author, $message))->deleteWhenMissingModels);
    }

    public function test_the_feed_row_carries_the_group_and_the_message(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');

        $this->mention($author, $group, $target);

        $row = DB::table('notifications')->where('notifiable_id', $target->getKey())->first();
        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertSame('group_talk_mention', $data['kind']);
        $this->assertSame($group->getKey(), $data['group_id']);
        $this->assertSame($author->getKey(), $data['author_id']);
        $this->assertArrayHasKey('message_id', $data);
    }

    public function test_the_mail_names_the_author_the_group_and_the_conversation(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'come and look',
        ]);

        $mail = (new GroupTalkMentionedNotification($author, $message))->toMail($target);
        $text = $this->renderMailText($mail);

        $this->assertStringContainsString($author->name, $text);
        $this->assertStringContainsString($group->name, $text);
        $this->assertStringContainsString('come and look', $text);
        // The message, not just the room: mail never passes through the feed's click-time re-check,
        // so the anchor is the only thing that says which line named them.
        $this->assertStringContainsString("/groups/{$group->getKey()}/talk?m={$message->getKey()}", $text);
    }

    public function test_the_member_can_turn_the_mail_off_and_keep_the_feed_row(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $notification = new GroupTalkMentionedNotification($author, $message);

        $this->assertSame(['mail', 'database'], $notification->via($target));

        $target->setNotificationSetting(NotificationKind::GroupTalkMention, NotificationChannel::Mail, false);

        $this->assertSame(['database'], $notification->via($target->fresh()));
    }

    public function test_disabling_the_template_drops_the_mail_channel(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = $this->joined($group, 'Bob');
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        DB::table('mail_templates')->insert(['key' => MailTemplate::GroupTalkMentionNotified->value, 'is_enabled' => false]);

        $this->assertSame(['database'], (new GroupTalkMentionedNotification($author, $message))->via($target));
    }
}
