<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\Queries\CountUnreadConversations;
use App\Features\DirectMessage\Queries\CountUnreadDirectMessages;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Two questions about the same receipts, so both are asserted over the same fixtures. */
class CountUnreadConversationsTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(?Member $sender, Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender?->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_several_messages_from_one_member_are_one_conversation(): void
    {
        [$sender, $viewer] = Member::factory()->count(2)->create();
        $this->deliver($sender, $viewer);
        $this->deliver($sender, $viewer);
        $this->deliver($sender, $viewer);

        $this->assertSame(1, (new CountUnreadConversations)($viewer));
        // The mailbox's own reading of the same three receipts, which the Classic caution keeps.
        $this->assertSame(3, (new CountUnreadDirectMessages)($viewer));
    }

    public function test_each_member_waiting_is_one(): void
    {
        [$first, $second, $viewer] = Member::factory()->count(3)->create();
        $this->deliver($first, $viewer);
        $this->deliver($second, $viewer);
        $this->deliver($second, $viewer);

        $this->assertSame(2, (new CountUnreadConversations)($viewer));
    }

    public function test_every_withdrawn_sender_adds_the_one_bucket(): void
    {
        [$present, $gone, $alsoGone, $viewer] = Member::factory()->count(4)->create();
        $this->deliver($present, $viewer);
        $this->deliver($gone, $viewer);
        $this->deliver($alsoGone, $viewer);
        $gone->delete();
        $alsoGone->delete();

        // The present member, plus the single conversation both departed members collapse into.
        $this->assertSame(2, (new CountUnreadConversations)($viewer));
    }

    public function test_the_bucket_counts_on_its_own(): void
    {
        $viewer = Member::factory()->create();
        $this->deliver(null, $viewer);

        $this->assertSame(1, (new CountUnreadConversations)($viewer));
    }

    public function test_reading_the_conversation_drops_it(): void
    {
        [$sender, $viewer] = Member::factory()->count(2)->create();
        $first = $this->deliver($sender, $viewer);
        $second = $this->deliver($sender, $viewer);

        $first->recipients()->update(['read_at' => now()]);
        $this->assertSame(1, (new CountUnreadConversations)($viewer));

        $second->recipients()->update(['read_at' => now()]);
        $this->assertSame(0, (new CountUnreadConversations)($viewer));
    }

    public function test_only_a_live_receipt_of_a_delivered_message_is_waiting(): void
    {
        [$sender, $viewer, $other] = Member::factory()->count(3)->create();
        $this->deliver($sender, $viewer, ['is_draft' => true]);
        $this->deliver($sender, $viewer, receipt: ['recipient_deleted_at' => now()]);
        $this->deliver($sender, $viewer, receipt: ['recipient_purged_at' => now()]);
        $this->deliver($sender, $other);

        $this->assertSame(0, (new CountUnreadConversations)($viewer));
    }

    public function test_is_zero_without_messages(): void
    {
        $this->assertSame(0, (new CountUnreadConversations)(Member::factory()->create()));
    }
}
