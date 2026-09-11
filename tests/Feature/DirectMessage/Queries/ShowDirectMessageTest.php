<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\Queries\ShowDirectMessage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PinsOrderBy;
use Tests\TestCase;

class ShowDirectMessageTest extends TestCase
{
    use PinsOrderBy;
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

    /** Ids are explicit: the row ids must not depend on the auto-increment position a prior test left. */
    public function test_trash_previous_and_next_cross_the_two_sides_in_list_order(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        $at = '2026-03-01 10:00:00';
        $sent = DirectMessage::factory()->create(['id' => 1, 'sender_id' => $me->getKey(), 'sender_deleted_at' => $at]);
        $receivedA = DirectMessage::factory()->create(['id' => 2, 'sender_id' => $other->getKey()]);
        DirectMessageRecipient::factory()->create(['id' => 1, 'direct_message_id' => 2, 'recipient_id' => $me->getKey(), 'recipient_deleted_at' => $at]);
        $receivedB = DirectMessage::factory()->create(['id' => 3, 'sender_id' => $other->getKey()]);
        DirectMessageRecipient::factory()->create(['id' => 2, 'direct_message_id' => 3, 'recipient_id' => $me->getKey(), 'recipient_deleted_at' => $at]);
        $earlier = DirectMessage::factory()->create(['id' => 4, 'sender_id' => $me->getKey(), 'sender_deleted_at' => '2026-02-01 10:00:00']);

        // The list reads [sent 1, received 3, received 2, earlier 4]: sent before received, then row id.
        $show = fn (DirectMessage $m) => app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $m->getKey());

        $this->assertSame([null, $receivedB->getKey()], [$show($sent)->nextId, $show($sent)->previousId]);
        $this->assertSame([$sent->getKey(), $receivedA->getKey()], [$show($receivedB)->nextId, $show($receivedB)->previousId]);
        $this->assertSame([$receivedB->getKey(), $earlier->getKey()], [$show($receivedA)->nextId, $show($receivedA)->previousId]);
        $this->assertSame([$receivedA->getKey(), null], [$show($earlier)->nextId, $show($earlier)->previousId]);
    }

    /** Both engines return a same-second neighbour in list order without the role arms, so the SQL is pinned. */
    public function test_the_neighbour_queries_compare_and_order_by_the_full_box_tuple(): void
    {
        [$me, $other] = Member::factory()->count(2)->create();
        $sent = DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => '2026-03-01 10:00:00']);
        $this->deliver($other, $me, receipt: ['recipient_deleted_at' => '2026-03-01 10:00:00']);

        $neighbours = $this->orderClausesOn('direct_messages', fn () => app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $sent->getKey()));
        $sql = $this->querySqlOn('direct_messages', fn () => app(ShowDirectMessage::class)($me, DirectMessageBox::Trash, $sent->getKey()));

        $this->assertContains('order by sort_at desc, role desc, row_id desc', $neighbours);
        $this->assertContains('order by sort_at asc, role asc, row_id asc', $neighbours);
        $this->assertSame(2, count(array_filter($sql, fn (string $q) => preg_match('/role < \? or \(role = \? and row_id < \?\)/', $q) === 1 || preg_match('/role > \? or \(role = \? and row_id > \?\)/', $q) === 1)));
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

    /** The window ranks a NULL time last on MySQL 8 and SQLite alike, so the timed receipt is the message's place. */
    public function test_a_timeless_duplicate_receipt_yields_to_the_timed_one(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $older = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 09:00:00']);
        $twice = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 10:00:00']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $twice->getKey(), 'recipient_id' => $recipient->getKey(), 'created_at' => null]);
        $newer = $this->deliver($sender, $recipient, receipt: ['created_at' => '2026-03-01 11:00:00']);

        $view = app(ShowDirectMessage::class)($recipient, DirectMessageBox::Receive, $twice->getKey());

        $this->assertSame($older->getKey(), $view->previousId);
        $this->assertSame($newer->getKey(), $view->nextId);
    }
}
