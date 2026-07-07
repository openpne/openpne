<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The OpenPNE 3 opMessagePluginUtil chain per channel: MessageNew on → deliver to anyone;
 * else MessageNewOnlyFriends on and the sender is a friend → deliver.
 */
class MessageReceivedNotificationGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_deliver_mail_and_database(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();

        $this->assertSame(['mail', 'database'], $this->channelsFor($sender, $recipient));
    }

    public function test_message_new_off_drops_mail_for_a_non_friend_sender(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Mail, false);

        $this->assertSame(['database'], $this->channelsFor($sender, $recipient));
    }

    public function test_only_friends_variant_still_covers_a_friend_sender(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($sender, $recipient);
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Mail, false);

        $this->assertSame(['mail', 'database'], $this->channelsFor($sender, $recipient));
    }

    public function test_both_kinds_off_drop_mail_even_for_a_friend_sender(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($sender, $recipient);
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Mail, false);
        $recipient->setNotificationSetting(NotificationKind::MessageNewOnlyFriends, NotificationChannel::Mail, false);

        $this->assertSame(['database'], $this->channelsFor($sender, $recipient));
    }

    public function test_broad_kind_covers_everyone_regardless_of_the_variant(): void
    {
        // OpenPNE 3 checks MessageNew first: while it is on, the friends-only toggle is inert.
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $recipient->setNotificationSetting(NotificationKind::MessageNewOnlyFriends, NotificationChannel::Mail, false);

        $this->assertSame(['mail', 'database'], $this->channelsFor($sender, $recipient));
    }

    public function test_web_channel_gates_the_database_record_independently(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Web, false);

        $this->assertSame(['mail'], $this->channelsFor($sender, $recipient));
    }

    public function test_all_channels_off_yields_no_delivery(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Mail, false);
        $recipient->setNotificationSetting(NotificationKind::MessageNew, NotificationChannel::Web, false);

        $this->assertSame([], $this->channelsFor($sender, $recipient));
    }

    /** @return list<string> */
    private function channelsFor(Member $sender, Member $recipient): array
    {
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);

        return (new MessageReceivedNotification($sender, $message))->via($recipient);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
