<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\Queries\ShowDirectMessage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowDirectMessageTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_opening_a_received_message_marks_it_read(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $message->getKey());

        $this->assertNotNull($view);
        $this->assertFalse($view->viewerIsSender);
        $this->assertTrue($view->counterparties[0]->is($sender)); // From = sender
        $this->assertNotNull(DirectMessageRecipient::query()
            ->where('direct_message_id', $message->getKey())->where('recipient_id', $recipient->getKey())
            ->value('read_at'));
    }

    public function test_received_show_404s_a_draft_and_a_non_recipient(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $draft = $this->deliver($sender, $recipient, ['is_draft' => true]);
        $delivered = $this->deliver($sender, $recipient);

        $this->assertNull(app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $draft->getKey()));
        $this->assertNull(app(ShowDirectMessage::class)($stranger, DirectMessageBox::Receive, $delivered->getKey()));
    }

    public function test_sent_show_resolves_for_the_sender_and_lists_recipients(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $view = app(ShowDirectMessage::class)($sender, DirectMessageBox::Sent, $message->getKey());

        $this->assertNotNull($view);
        $this->assertTrue($view->viewerIsSender);
        $this->assertTrue($view->counterparties[0]->is($recipient)); // To = recipient
    }

    public function test_draft_box_has_no_show_page(): void
    {
        $sender = Member::factory()->create();
        $draft = DirectMessage::factory()->draft()->create(['sender_id' => $sender->getKey()]);

        $this->assertNull(app(ShowDirectMessage::class)($sender, DirectMessageBox::Draft, $draft->getKey()));
    }

    public function test_trash_show_resolves_for_either_side_but_not_after_purge(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        $asSender = DirectMessage::factory()->trashedBySender()->create(['sender_id' => $me->getKey()]);
        $asRecipient = $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now()]);
        $purged = DirectMessage::factory()->purgedBySender()->create(['sender_id' => $me->getKey()]);

        $this->assertNotNull(app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $asSender->getKey()));
        $this->assertNotNull(app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $asRecipient->getKey()));
        $this->assertNull(app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $purged->getKey()));
    }

    public function test_previous_and_next_follow_the_inbox_order_receipt_time_then_row(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // Authored later but received earlier: the inbox lists by the receipt, so must the links.
        $receivedFirst = $this->deliver($sender, $recipient, ['created_at' => '2026-03-01 12:00:00'], ['created_at' => '2026-03-01 09:00:00']);
        $middle = $this->deliver($sender, $recipient, ['created_at' => '2026-03-01 08:00:00'], ['created_at' => '2026-03-01 10:00:00']);
        $receivedLast = $this->deliver($sender, $recipient, ['created_at' => '2026-03-01 07:00:00'], ['created_at' => '2026-03-01 11:00:00']);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $middle->getKey());

        $this->assertSame($receivedFirst->getKey(), $view->previousId);
        $this->assertSame($receivedLast->getKey(), $view->nextId);
    }

    public function test_previous_and_next_split_one_receipt_second_at_the_receipt_row(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $rows = [];
        foreach (range(1, 3) as $i) {
            $rows[] = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 10:00:00']);
        }

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $rows[1]->getKey());

        $this->assertSame($rows[0]->getKey(), $view->previousId);
        $this->assertSame($rows[2]->getKey(), $view->nextId);
        $this->assertNull(app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $rows[0]->getKey())->previousId);
        $this->assertNull(app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $rows[2]->getKey())->nextId);
    }

    public function test_sent_previous_and_next_follow_created_at_then_id(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $older = $this->deliver($sender, $recipient, ['created_at' => '2026-03-01 10:00:00']);
        $middle = $this->deliver($sender, $recipient, ['created_at' => '2026-03-01 10:00:00']);
        $newer = $this->deliver($sender, $recipient, ['created_at' => '2026-03-02 10:00:00']);

        $view = app(ShowDirectMessage::class)($sender, DirectMessageBox::Sent, $middle->getKey());

        $this->assertSame($older->getKey(), $view->previousId);
        $this->assertSame($newer->getKey(), $view->nextId);
    }

    public function test_trash_previous_and_next_cross_the_two_sides_in_list_order(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        $at = '2026-03-01 10:00:00';
        // Receipts elsewhere first, so the receipt row id outruns the message ids and row_id alone would flip the order.
        DirectMessageRecipient::factory()->count(5)->create();
        $sent = DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => $at]);
        $received = $this->deliver($other, $me, receipt: ['recipient_deleted_at' => $at]);
        $earlier = DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => '2026-02-01 10:00:00']);

        $fromSent = app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $sent->getKey());
        $fromReceived = app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $received->getKey());

        $this->assertSame($received->getKey(), $fromSent->previousId);
        $this->assertNull($fromSent->nextId);
        $this->assertSame($earlier->getKey(), $fromReceived->previousId);
        $this->assertSame($sent->getKey(), $fromReceived->nextId);
    }

    /** OpenPNE 3 data may carry two receipts of one message for one member; neither is the other's neighbour. */
    public function test_a_duplicate_receipt_does_not_make_a_message_its_own_neighbour(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $first = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 08:00:00']);
        $twice = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 09:00:00']);
        $between = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 10:00:00']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $twice->getKey(), 'recipient_id' => $recipient->getKey(), 'created_at' => '2026-03-01 11:00:00']);
        $last = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 12:00:00']);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $twice->getKey());

        // Placed at its later (11:00) row: the 10:00 message is behind it, never the 08:00 one.
        $this->assertSame($between->getKey(), $view->previousId);
        $this->assertSame($last->getKey(), $view->nextId);
        // Walking back from the newest visits each message once and ends at the oldest.
        $walk = [];
        for ($id = $last->getKey(); $id !== null; $id = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $id)->previousId) {
            $walk[] = $id;
        }
        $this->assertSame([$last->getKey(), $twice->getKey(), $between->getKey(), $first->getKey()], $walk);
    }

    public function test_a_receipt_with_no_time_has_no_neighbours(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);
        $timeless = $this->deliver($sender, $recipient);
        DirectMessageRecipient::query()->where('direct_message_id', $timeless->getKey())->update(['created_at' => null]);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $timeless->getKey());

        $this->assertNotNull($view);
        $this->assertNull($view->previousId);
        $this->assertNull($view->nextId);
    }
}
