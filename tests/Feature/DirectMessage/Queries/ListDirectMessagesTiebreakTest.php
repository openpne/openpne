<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\Queries\ListDirectMessages;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\Support\PinsOrderBy;
use Tests\TestCase;

class ListDirectMessagesTiebreakTest extends TestCase
{
    use PinsOrderBy;
    use RefreshDatabase;

    private const AT = '2026-03-01 12:00:00';

    public function test_the_inbox_splits_one_receipt_second_at_the_receipt_row(): void
    {
        [$sender, $me] = Member::factory()->count(2)->create()->all();
        $messageIds = [];
        foreach (range(1, 25) as $i) {
            // Authored out of order: the inbox is the receipt's order, not the message's.
            $m = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'created_at' => '2026-02-'.sprintf('%02d', 26 - $i).' 09:00:00']);
            DirectMessageRecipient::factory()->create(['direct_message_id' => $m->getKey(), 'recipient_id' => $me->getKey(), 'created_at' => self::AT]);
            $messageIds[] = $m->getKey();
        }
        $expected = array_reverse($messageIds);

        $this->assertPagesSplit($expected, fn (int $page) => $this->box($me, DirectMessageBox::Receive, $page));
    }

    public function test_the_sent_box_splits_one_second_at_the_message_id(): void
    {
        [$me, $other] = Member::factory()->count(2)->create()->all();
        $ids = [];
        foreach (range(1, 25) as $i) {
            $m = DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'created_at' => self::AT]);
            DirectMessageRecipient::factory()->create(['direct_message_id' => $m->getKey(), 'recipient_id' => $other->getKey()]);
            $ids[] = $m->getKey();
        }

        $this->assertPagesSplit(array_reverse($ids), fn (int $page) => $this->box($me, DirectMessageBox::Sent, $page));
    }

    /** Both sides trashed in one second: sent rows precede received ones, each side by its own row id. */
    public function test_the_trash_splits_one_second_by_side_then_row(): void
    {
        [$me, $other] = Member::factory()->count(2)->create()->all();
        $received = [];
        foreach (range(1, 13) as $i) {
            $m = DirectMessage::factory()->create(['sender_id' => $other->getKey()]);
            DirectMessageRecipient::factory()->create(['direct_message_id' => $m->getKey(), 'recipient_id' => $me->getKey(), 'recipient_deleted_at' => self::AT]);
            $received[] = $m->getKey();
        }
        $sent = [];
        foreach (range(1, 12) as $i) {
            $sent[] = DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => self::AT])->getKey();
        }
        $expected = [...array_reverse($sent), ...array_reverse($received)];

        $this->assertPagesSplit($expected, fn (int $page) => $this->box($me, DirectMessageBox::Trash, $page));
    }

    public function test_every_box_orders_by_its_time_then_a_unique_key(): void
    {
        [$me, $other] = Member::factory()->count(2)->create()->all();
        $m = DirectMessage::factory()->create(['sender_id' => $other->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $m->getKey(), 'recipient_id' => $me->getKey()]);
        // An empty box never runs its page query, so each box needs one row to log an order clause.
        DirectMessage::factory()->create(['sender_id' => $me->getKey(), 'sender_deleted_at' => self::AT]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => DirectMessage::factory()->create(['sender_id' => $me->getKey()])->getKey(), 'recipient_id' => $other->getKey()]);

        $inbox = $this->orderClausesOn('direct_message_recipients', fn () => $this->box($me, DirectMessageBox::Receive, 1));
        $sentBox = $this->orderClausesOn('direct_messages', fn () => $this->box($me, DirectMessageBox::Sent, 1));
        $trash = $this->orderClausesOn('direct_messages', fn () => $this->box($me, DirectMessageBox::Trash, 1));

        $this->assertSame(['order by created_at desc, id desc'], array_values(array_unique($inbox)));
        $this->assertSame(['order by created_at desc, id desc'], array_values(array_unique($sentBox)));
        $this->assertContains('order by sort_at desc, role desc, row_id desc', $trash);
    }

    /** @param  list<int>  $expected  message ids in list order, 25 of them */
    private function assertPagesSplit(array $expected, callable $page): void
    {
        $first = array_map(fn ($item) => $item->messageId, $page(1)->items());
        $second = array_map(fn ($item) => $item->messageId, $page(2)->items());

        $this->assertSame(array_slice($expected, 0, 20), $first);
        $this->assertSame(array_slice($expected, 20), $second);
    }

    private function box(Member $viewer, DirectMessageBox $box, int $page)
    {
        Paginator::currentPageResolver(fn () => $page);
        try {
            return (new ListDirectMessages)($viewer, $box, 20);
        } finally {
            Paginator::currentPageResolver(fn () => 1);
        }
    }
}
