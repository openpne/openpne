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

    public function test_previous_and_next_walk_the_box_by_id(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $older = $this->deliver($sender, $recipient);
        $middle = $this->deliver($sender, $recipient);
        $newer = $this->deliver($sender, $recipient);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $middle->getKey());

        $this->assertSame($older->getKey(), $view->previousId); // older = smaller id
        $this->assertSame($newer->getKey(), $view->nextId);     // newer = larger id
    }
}
