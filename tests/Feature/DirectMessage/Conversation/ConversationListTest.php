<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\ConversationSummary;
use App\Features\DirectMessage\Queries\ConversationList;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * The conversation list: who the viewer is corresponding with, in the order they last wrote, with
 * what each leads with and what is waiting in it.
 */
class ConversationListTest extends ConversationTestCase
{
    private function list(Member $viewer, int $perPage = ConversationList::PER_PAGE): array
    {
        return (new ConversationList)($viewer, $perPage)->items();
    }

    /** @param  list<ConversationSummary>  $rows */
    private function names(array $rows): array
    {
        return array_map(static fn (ConversationSummary $row): string => $row->counterpart?->name ?? 'withdrawn', $rows);
    }

    private function at(?Member $sender, ?Member $recipient, string $at, array $message = [], array $receipt = []): DirectMessage
    {
        $when = Carbon::parse($at);

        return $this->deliver($sender, $recipient, ['created_at' => $when, 'updated_at' => $when, ...$message], $receipt);
    }

    public function test_conversations_are_ordered_by_what_was_last_said(): void
    {
        [$viewer, $first, $second, $third] = Member::factory()->count(4)->create();
        $first->forceFill(['name' => 'first'])->save();
        $second->forceFill(['name' => 'second'])->save();
        $third->forceFill(['name' => 'third'])->save();

        $this->at($first, $viewer, '2026-08-14 09:00:00');
        $this->at($viewer, $third, '2026-08-14 11:00:00');
        $this->at($second, $viewer, '2026-08-14 10:00:00');

        $this->assertSame(['third', 'second', 'first'], $this->names($this->list($viewer)));
    }

    public function test_the_order_is_total_when_two_conversations_share_a_second(): void
    {
        // MySQL's timestamp resolution is one second, so the tuple's id half is what makes the order
        // a total one — and the page cut needs it to be.
        [$viewer, $earlier, $later] = Member::factory()->count(3)->create();
        $earlier->forceFill(['name' => 'earlier'])->save();
        $later->forceFill(['name' => 'later'])->save();

        $this->at($earlier, $viewer, '2026-08-14 09:00:00');
        $this->at($later, $viewer, '2026-08-14 09:00:00');

        $this->assertSame(['later', 'earlier'], $this->names($this->list($viewer)));
    }

    /** The order is decided before the page is cut: a conversation talked in belongs at the top. */
    public function test_a_conversation_older_by_id_still_leads_when_it_was_written_in_last(): void
    {
        $viewer = Member::factory()->create();
        $oldest = Member::factory()->create(['name' => 'oldest']);
        $this->at($oldest, $viewer, '2026-08-14 09:00:00');

        foreach (range(1, 25) as $i) {
            $other = Member::factory()->create(['name' => "other {$i}"]);
            $this->at($other, $viewer, '2026-08-14 09:0'.($i % 10).':00');
        }

        // The oldest correspondence answers again, after twenty-five newer ones exist.
        $this->at($oldest, $viewer, '2026-08-14 23:00:00');

        $this->assertSame('oldest', $this->names($this->list($viewer))[0]);
    }

    public function test_a_conversation_emptied_from_the_viewers_side_leaves_only_their_list(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $sent = $this->at($viewer, $other, '2026-08-14 09:00:00');
        $received = $this->at($other, $viewer, '2026-08-14 09:01:00');

        $this->assertCount(1, $this->list($viewer));

        $sent->forceFill(['sender_deleted_at' => now()])->save();
        $received->recipients()->update(['recipient_deleted_at' => now()]);

        $this->assertSame([], $this->names($this->list($viewer)));
        // The other side reads the same two rows through their own columns, which nothing touched.
        $this->assertSame([$viewer->name], $this->names($this->list($other)));
    }

    public function test_every_withdrawn_member_collapses_into_one_row(): void
    {
        $viewer = Member::factory()->create();
        [$gone, $alsoGone] = Member::factory()->count(2)->create();
        $this->at($gone, $viewer, '2026-08-14 09:00:00');
        $this->at($alsoGone, $viewer, '2026-08-14 09:01:00', ['body' => 'the last word']);
        $gone->delete();
        $alsoGone->delete();

        $rows = $this->list($viewer);

        $this->assertSame(['withdrawn'], $this->names($rows));
        // One row, and it leads with the newest of everything in the bucket.
        $this->assertSame('the last word', $rows[0]->latest->body);
    }

    public function test_a_present_counterpart_is_never_the_withdrawn_row(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00');

        $this->assertSame([$other->name], $this->names($this->list($viewer)));
    }

    public function test_the_unread_count_is_the_received_arm_alone(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00');
        $this->at($other, $viewer, '2026-08-14 09:01:00');
        $read = $this->at($other, $viewer, '2026-08-14 09:02:00');
        $read->recipients()->update(['read_at' => now()]);
        // The viewer's own message carries no unread state — writing is not something to be read.
        $this->at($viewer, $other, '2026-08-14 09:03:00');

        // Three received, one of them opened; the sent one is not the viewer's to read.
        $this->assertSame(2, $this->list($viewer)[0]->unread);
        // The other side counts that sent message, and none of their own three.
        $this->assertSame(1, $this->list($other)[0]->unread);
    }

    public function test_a_trashed_receipt_is_not_waiting_to_be_read(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00');
        $trashed = $this->at($other, $viewer, '2026-08-14 09:01:00');
        $trashed->recipients()->update(['recipient_deleted_at' => now()]);

        $this->assertSame(1, $this->list($viewer)[0]->unread);
    }

    public function test_a_draft_is_in_no_conversation(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        DirectMessage::factory()->draft()->create([
            'sender_id' => $viewer->getKey(),
            'draft_recipient_id' => $other->getKey(),
        ]);

        $this->assertSame([], $this->names($this->list($viewer)));
    }

    public function test_the_preview_falls_back_to_the_subject_when_a_message_has_no_body(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00', ['subject' => 'Only a subject', 'body' => '']);

        $row = $this->list($viewer)[0];

        $this->assertSame('', (string) $row->latest->body);
        $this->assertSame('Only a subject', $row->latest->subject);
    }

    public function test_the_pager_cuts_a_page_the_order_has_already_decided(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 5) as $i) {
            $other = Member::factory()->create(['name' => "other {$i}"]);
            $this->at($other, $viewer, '2026-08-14 09:0'.$i.':00');
        }

        $page = (new ConversationList)($viewer, 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame(['other 5', 'other 4'], $this->names($page->items()));
    }
}
