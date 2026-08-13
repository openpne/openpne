<?php

namespace Tests\Feature\DirectMessage;

use App\Features\Block\BlockLookup;
use App\Features\DirectMessage\DirectMessageAccess;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectMessageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_may_view_their_own_message_including_a_draft(): void
    {
        $sender = Member::factory()->create();
        $message = DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey()]);

        $this->assertTrue(DirectMessageAccess::canViewMessage($message, $sender));
    }

    public function test_recipient_may_view_a_delivered_message(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->assertTrue(DirectMessageAccess::canViewMessage($message->fresh(), $recipient));
    }

    public function test_draft_recipient_may_not_view_the_draft(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // OpenPNE 3 stores the intended recipient on a draft too; it must stay invisible to them.
        $message = DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->assertFalse(DirectMessageAccess::canViewMessage($message->fresh(), $recipient));
    }

    public function test_purge_revokes_the_purging_sides_view(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = DirectMessage::factory()->purgedBySender()->create(['sender_id' => $sender->getKey()]);
        DirectMessageRecipient::factory()->purgedByRecipient()
            ->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->assertFalse(DirectMessageAccess::canViewMessage($message->fresh(), $sender));
        $this->assertFalse(DirectMessageAccess::canViewMessage($message->fresh(), $recipient));
    }

    public function test_a_third_party_may_not_view(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->assertFalse(DirectMessageAccess::canViewMessage($message->fresh(), $stranger));
    }

    public function test_can_send_to_a_normal_member(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->assertTrue(DirectMessageAccess::canSend($sender, $recipient));
    }

    public function test_cannot_send_to_self(): void
    {
        $member = Member::factory()->create();

        $this->assertFalse(DirectMessageAccess::canSend($member, $member));
    }

    public function test_cannot_send_to_a_login_rejected_member(): void
    {
        $sender = Member::factory()->create();
        $banned = Member::factory()->create(['is_login_rejected' => true]);

        $this->assertFalse(DirectMessageAccess::canSend($sender, $banned));
    }

    public function test_cannot_send_across_a_block_in_either_direction(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $recipient->getKey(),
            'blocked_id' => $sender->getKey(),
            'created_at' => now(),
        ]);

        $this->assertTrue(BlockLookup::hasAnyBlockBetween($sender, $recipient));
        $this->assertFalse(DirectMessageAccess::canSend($sender, $recipient));
        $this->assertFalse(DirectMessageAccess::canSend($recipient, $sender));
    }
}
