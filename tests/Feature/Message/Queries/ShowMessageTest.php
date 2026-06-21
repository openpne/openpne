<?php

namespace Tests\Feature\Message\Queries;

use App\Features\Message\MessageBox;
use App\Features\Message\Queries\ShowMessage;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowMessageTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): Message
    {
        $m = Message::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        MessageRecipient::factory()->create([...['message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_opening_a_received_message_marks_it_read(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $view = (new ShowMessage)($recipient, MessageBox::Receive, $message->getKey());

        $this->assertNotNull($view);
        $this->assertFalse($view->viewerIsSender);
        $this->assertTrue($view->counterparties[0]->is($sender)); // From = sender
        $this->assertNotNull(MessageRecipient::query()
            ->where('message_id', $message->getKey())->where('recipient_id', $recipient->getKey())
            ->value('read_at'));
    }

    public function test_received_show_404s_a_draft_and_a_non_recipient(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $draft = $this->deliver($sender, $recipient, ['is_draft' => true]);
        $delivered = $this->deliver($sender, $recipient);

        $this->assertNull((new ShowMessage)($recipient, MessageBox::Receive, $draft->getKey()));
        $this->assertNull((new ShowMessage)($stranger, MessageBox::Receive, $delivered->getKey()));
    }

    public function test_sent_show_resolves_for_the_sender_and_lists_recipients(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $view = (new ShowMessage)($sender, MessageBox::Sent, $message->getKey());

        $this->assertNotNull($view);
        $this->assertTrue($view->viewerIsSender);
        $this->assertTrue($view->counterparties[0]->is($recipient)); // To = recipient
    }

    public function test_draft_box_has_no_show_page(): void
    {
        $sender = Member::factory()->create();
        $draft = Message::factory()->draft()->create(['sender_id' => $sender->getKey()]);

        $this->assertNull((new ShowMessage)($sender, MessageBox::Draft, $draft->getKey()));
    }

    public function test_trash_show_resolves_for_either_side_but_not_after_purge(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        $asSender = Message::factory()->trashedBySender()->create(['sender_id' => $me->getKey()]);
        $asRecipient = $this->deliver($other, $me, receipt: ['recipient_deleted_at' => now()]);
        $purged = Message::factory()->purgedBySender()->create(['sender_id' => $me->getKey()]);

        $this->assertNotNull((new ShowMessage)($me, MessageBox::Trash, $asSender->getKey()));
        $this->assertNotNull((new ShowMessage)($me, MessageBox::Trash, $asRecipient->getKey()));
        $this->assertNull((new ShowMessage)($me, MessageBox::Trash, $purged->getKey()));
    }

    public function test_previous_and_next_walk_the_box_by_id(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $older = $this->deliver($sender, $recipient);
        $middle = $this->deliver($sender, $recipient);
        $newer = $this->deliver($sender, $recipient);

        $view = (new ShowMessage)($recipient, MessageBox::Receive, $middle->getKey());

        $this->assertSame($older->getKey(), $view->previousId); // older = smaller id
        $this->assertSame($newer->getKey(), $view->nextId);     // newer = larger id
    }
}
