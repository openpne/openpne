<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Features\DirectMessage\Queries\ListDirectMessages;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDirectMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_inbox_lists_delivered_messages_for_the_recipient(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Hello there']);

        $page = (new ListDirectMessages)($recipient, DirectMessageBox::Receive);

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

        $this->assertCount(1, (new ListDirectMessages)($recipient, DirectMessageBox::Receive)->items());
    }

    public function test_sent_box_lists_the_senders_delivered_messages(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Sent one']);
        DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey()]); // draft: not in sent

        $page = (new ListDirectMessages)($sender, DirectMessageBox::Sent);

        $this->assertCount(1, $page->items());
        $this->assertSame('Sent one', $page->items()[0]->subject);
        $this->assertTrue($page->items()[0]->counterparty->is($recipient)); // To = recipient
    }

    public function test_draft_box_lists_undelivered_drafts_only_and_excludes_deleted(): void
    {
        $sender = Member::factory()->create();
        DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey()]);
        DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);                          // sent: not a draft
        DirectMessage::factory()->draft()->trashedBySender()->create(['sender_id' => $sender->getKey()]); // trashed draft: hidden

        $this->assertCount(1, (new ListDirectMessages)($sender, DirectMessageBox::Draft)->items());
    }

    public function test_draft_box_shows_the_pending_recipient_from_the_column(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // A draft has no receipt; its recipient lives on the draft_recipient_id column.
        $draft = DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey(), 'draft_recipient_id' => $recipient->getKey()]);

        $item = (new ListDirectMessages)($sender, DirectMessageBox::Draft)->items()[0];

        $this->assertSame($draft->getKey(), $item->messageId);
        $this->assertTrue($item->counterparty->is($recipient)); // To = the draft's pending recipient
    }

    public function test_a_real_draft_creates_no_receipt_and_never_reaches_its_recipient(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // The real compose flow: a draft creates no receipt, so the recipient has nothing, anywhere.
        app(SendDirectMessage::class)($sender, new DirectMessageComposeData($recipient->getKey(), 'Secret', 'Body'), asDraft: true);

        $this->assertSame(0, DirectMessageRecipient::count());
        $this->assertCount(0, (new ListDirectMessages)($recipient, DirectMessageBox::Receive)->items());
        $this->assertCount(0, (new ListDirectMessages)($recipient, DirectMessageBox::Trash)->items());
    }

    public function test_inbox_orders_and_dates_by_the_receipt_not_the_message(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // Authored earlier but delivered (receipt created) later — OpenPNE 3 sorts/dates by the receipt.
        $earlierAuthored = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'created_at' => now()->subDays(5), 'subject' => 'authored earlier']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $earlierAuthored->getKey(), 'recipient_id' => $recipient->getKey(), 'created_at' => now()]);
        $laterAuthored = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'created_at' => now(), 'subject' => 'authored later']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $laterAuthored->getKey(), 'recipient_id' => $recipient->getKey(), 'created_at' => now()->subDays(5)]);

        $items = (new ListDirectMessages)($recipient, DirectMessageBox::Receive)->items();

        $this->assertSame('authored earlier', $items[0]->subject); // later receipt sorts first
        $this->assertSame('authored later', $items[1]->subject);
        $this->assertTrue($items[0]->date->isToday());             // date is the receipt's, not the message's
    }

    public function test_trash_orders_and_dates_by_the_moved_to_trash_time(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => now()->subDay(), 'subject' => 'trashed earlier']);
        $recentlyTrashed = DirectMessage::factory()->create(['sender_id' => $other->getKey(), 'subject' => 'trashed later']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $recentlyTrashed->getKey(), 'recipient_id' => $me->getKey(), 'recipient_deleted_at' => now()]);

        $items = (new ListDirectMessages)($me, DirectMessageBox::Trash)->items();

        $this->assertSame('trashed later', $items[0]->subject);  // most recently trashed first
        $this->assertSame('trashed earlier', $items[1]->subject);
        $this->assertTrue($items[0]->date->isToday());           // date is the trash time
    }

    public function test_trash_mixes_sender_and_recipient_trashed_and_excludes_purged(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        // A message I trashed as sender.
        DirectMessage::factory()->trashedBySender()->create(['sender_id' => $me->getKey()]);
        // A message I trashed as recipient.
        $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now()]);
        // Purged on each side: excluded.
        DirectMessage::factory()->purgedBySender()->create(['sender_id' => $me->getKey()]);
        $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);

        $this->assertCount(2, (new ListDirectMessages)($me, DirectMessageBox::Trash)->items());
    }
}
