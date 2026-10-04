<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\ConversationSummary;
use App\Features\DirectMessage\Queries\ConversationList;
use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Features\DirectMessage\Queries\ConversationUnreadSnapshot;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * The list and the per-conversation reads share no code, so one seeded matrix holds them together:
 * every row the list draws must say what opening that conversation says.
 */
class ConversationListBeltTest extends ConversationTestCase
{
    /**
     * A matrix with something of every kind in it: both arms, both sides' trash, read and unread
     * receipts, a drafted message, a conversation the viewer has emptied, a withdrawn sender and
     * recipient, a same-second pair, a doubled receipt and a two-recipient send.
     *
     * @return array{Member, list<Member>}
     */
    private function matrix(): array
    {
        $viewer = Member::factory()->create(['name' => 'viewer']);
        $others = Member::factory()->count(4)->create()->values()->all();
        [$busy, $quiet, $emptied, $onlySent] = $others;

        $at = static fn (int $minute): Carbon => Carbon::parse('2026-08-14 09:00:00')->addMinutes($minute);

        // Both arms, read and unread, plus one the viewer has trashed on their own side.
        $this->deliver($busy, $viewer, ['body' => 'b1', 'created_at' => $at(1), 'updated_at' => $at(1)]);
        $this->deliver($busy, $viewer, ['body' => 'b2', 'created_at' => $at(2), 'updated_at' => $at(2)], ['read_at' => $at(3)]);
        $this->deliver($viewer, $busy, ['body' => 'b3', 'created_at' => $at(4), 'updated_at' => $at(4)]);
        $trashedHere = $this->deliver($busy, $viewer, ['body' => 'b4', 'created_at' => $at(9), 'updated_at' => $at(9)]);
        $trashedHere->recipients()->update(['recipient_deleted_at' => $at(10)]);
        // Their side trashed it; the viewer's copy is untouched, so it stays in the viewer's list.
        $trashedThere = $this->deliver($viewer, $busy, ['body' => 'b5', 'created_at' => $at(5), 'updated_at' => $at(5)]);
        $trashedThere->recipients()->update(['recipient_deleted_at' => $at(6)]);

        // One message each way, both opened.
        $this->deliver($quiet, $viewer, ['body' => 'q1', 'created_at' => $at(7), 'updated_at' => $at(7)], ['read_at' => $at(8)]);

        // Emptied from the viewer's side: nothing of it is theirs any more.
        $goneSent = $this->deliver($viewer, $emptied, ['body' => 'e1', 'created_at' => $at(11), 'updated_at' => $at(11)]);
        $goneSent->forceFill(['sender_deleted_at' => $at(12)])->save();
        $goneReceived = $this->deliver($emptied, $viewer, ['body' => 'e2', 'created_at' => $at(13), 'updated_at' => $at(13)]);
        $goneReceived->recipients()->update(['recipient_purged_at' => $at(14)]);

        // Only ever written to, never answered.
        $this->deliver($viewer, $onlySent, ['body' => 's1', 'created_at' => $at(15), 'updated_at' => $at(15)]);

        // A draft the viewer is still writing: no receipt, so it is in neither arm of anything.
        DirectMessage::factory()->draft()->create([
            'sender_id' => $viewer->getKey(),
            'draft_recipient_id' => $quiet->getKey(),
        ]);

        // A sender who has since left: the withdrawn bucket.
        $departed = Member::factory()->create();
        $this->deliver($departed, $viewer, ['body' => 'w1', 'created_at' => $at(16), 'updated_at' => $at(16)]);
        $departed->delete();
        // A same-second pair, one each way: the newer is the higher id.
        $this->deliver($busy, $viewer, ['body' => 'b6', 'created_at' => $at(17), 'updated_at' => $at(17)]);
        $this->deliver($viewer, $busy, ['body' => 'b7', 'created_at' => $at(17), 'updated_at' => $at(17)]);
        // Two receipts naming the viewer for one message (upgraded data carries them): one unread.
        $doubled = $this->deliver($quiet, $viewer, ['body' => 'q2', 'created_at' => $at(18), 'updated_at' => $at(18)]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $doubled->getKey(), 'recipient_id' => $viewer->getKey()]);
        // One send to two recipients: the shared latest of both conversations.
        $shared = $this->deliver($viewer, $quiet, ['body' => 's2', 'created_at' => $at(19), 'updated_at' => $at(19)]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $shared->getKey(), 'recipient_id' => $onlySent->getKey()]);
        // A recipient who has since left: the withdrawn bucket, from the sent arm.
        $left = Member::factory()->create();
        $this->deliver($viewer, $left, ['body' => 'w2', 'created_at' => $at(20), 'updated_at' => $at(20)]);
        $left->delete();

        return [$viewer, $others];
    }

    public function test_each_row_says_what_opening_that_conversation_says(): void
    {
        [$viewer] = $this->matrix();
        $messages = new ConversationMessages;
        $unread = new ConversationUnreadSnapshot;

        $rows = (new ConversationList)($viewer)->items();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            /** @var ConversationSummary $row */
            $counterpart = $row->counterpart;
            $name = $counterpart?->name ?? 'the withdrawn bucket';

            $conversation = $messages->latest($viewer, $counterpart)->messages;
            $this->assertSame(
                (int) $conversation->last()?->getKey(),
                (int) $row->latest->getKey(),
                "the row for {$name} leads with a message the conversation does not end on",
            );
            $this->assertSame(
                $unread($viewer, $counterpart)['count'] ?? 0,
                $row->unread,
                "the row for {$name} counts a different unread than the conversation does",
            );
        }
    }

    public function test_the_rows_are_exactly_the_conversations_with_anything_in_them(): void
    {
        [$viewer, $others] = $this->matrix();
        $messages = new ConversationMessages;

        $listed = array_map(
            static fn (ConversationSummary $row): ?int => $row->counterpart?->getKey(),
            (new ConversationList)($viewer)->items(),
        );

        // Every member on the site, plus the withdrawn bucket: a conversation is listed exactly when
        // opening it shows a message.
        $candidates = [...array_map(static fn (Member $m): ?Member => $m, $others), null];
        foreach ($candidates as $candidate) {
            $hasMessages = $messages->latest($viewer, $candidate)->messages->isNotEmpty();
            $this->assertSame(
                $hasMessages,
                in_array($candidate?->getKey(), $listed, true),
                'the list and the conversation disagree about '.($candidate?->name ?? 'the withdrawn bucket'),
            );
        }
    }
}
