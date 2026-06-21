<?php

namespace Tests\Feature\Message\Queries;

use App\Features\Message\MessageBox;
use App\Features\Message\Queries\ListMessages;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): Message
    {
        $m = Message::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        MessageRecipient::factory()->create([...['message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_inbox_lists_delivered_messages_for_the_recipient(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Hello there']);

        $page = (new ListMessages)($recipient, MessageBox::Receive);

        $this->assertCount(1, $page->items());
        $item = $page->items()[0];
        $this->assertSame('Hello there', $item->subject);
        $this->assertTrue($item->counterparty->is($sender)); // From = sender
        $this->assertTrue($item->unread);
    }

    public function test_inbox_excludes_drafts_recipient_deleted_and_purged(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);                                              // visible
        $this->deliver($sender, $recipient, ['is_draft' => true]);                        // draft: hidden
        $this->deliver($sender, $recipient, receipt: ['recipient_deleted_at' => now()]);  // trashed: hidden
        $this->deliver($sender, $recipient, receipt: ['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);

        $this->assertCount(1, (new ListMessages)($recipient, MessageBox::Receive)->items());
    }

    public function test_sent_box_lists_the_senders_delivered_messages(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Sent one']);
        Message::factory()->draft()->create(['sender_id' => $sender->getKey()]); // draft: not in sent

        $page = (new ListMessages)($sender, MessageBox::Sent);

        $this->assertCount(1, $page->items());
        $this->assertSame('Sent one', $page->items()[0]->subject);
        $this->assertTrue($page->items()[0]->counterparty->is($recipient)); // To = recipient
    }

    public function test_draft_box_lists_undelivered_drafts_only_and_excludes_deleted(): void
    {
        $sender = Member::factory()->create();
        Message::factory()->draft()->create(['sender_id' => $sender->getKey()]);
        Message::factory()->create(['sender_id' => $sender->getKey()]);                          // sent: not a draft
        Message::factory()->draft()->trashedBySender()->create(['sender_id' => $sender->getKey()]); // trashed draft: hidden

        $this->assertCount(1, (new ListMessages)($sender, MessageBox::Draft)->items());
    }

    public function test_trash_mixes_sender_and_recipient_trashed_and_excludes_purged(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        // A message I trashed as sender.
        Message::factory()->trashedBySender()->create(['sender_id' => $me->getKey()]);
        // A message I trashed as recipient.
        $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now()]);
        // Purged on each side: excluded.
        Message::factory()->purgedBySender()->create(['sender_id' => $me->getKey()]);
        $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);

        $this->assertCount(2, (new ListMessages)($me, MessageBox::Trash)->items());
    }
}
