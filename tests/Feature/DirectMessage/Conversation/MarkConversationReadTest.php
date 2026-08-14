<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * "I have read this conversation as far as here": the client names a message, the server resolves
 * its position, and every receipt of this conversation still waiting at or before it is opened.
 */
class MarkConversationReadTest extends ConversationTestCase
{
    /** One message at a fixed minute, so the tuple order is the writing order. */
    private function at(?Member $sender, ?Member $recipient, string $body, int $minute, array $receipt = []): DirectMessage
    {
        $when = Carbon::parse('2026-08-14 09:00:00')->addMinutes($minute);

        return $this->deliver($sender, $recipient, ['body' => $body, 'created_at' => $when, 'updated_at' => $when], $receipt);
    }

    private function readAt(DirectMessage $message, Member $recipient): ?string
    {
        return DB::table('direct_message_recipients')
            ->where('direct_message_id', $message->getKey())
            ->where('recipient_id', $recipient->getKey())
            ->value('read_at');
    }

    private function report(Member $viewer, ?Member $counterpart, int $messageId): TestResponse
    {
        $path = $counterpart === null ? '/messages/withdrawn' : "/messages/{$counterpart->getKey()}";

        return $this->actingAs($viewer)->postJson("{$path}/read", ['messageId' => $messageId]);
    }

    public function test_everything_waiting_up_to_the_named_message_is_read(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $oldest = $this->at($other, $viewer, 'first', 0);
        $named = $this->at($other, $viewer, 'as far as here', 1);
        $newer = $this->at($other, $viewer, 'arrived since', 2);

        $this->report($viewer, $other, $named->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($oldest, $viewer));
        $this->assertNotNull($this->readAt($named, $viewer));
        $this->assertNull($this->readAt($newer, $viewer), 'a message below the fold has not been read');
    }

    /** The foot of a conversation is often the reader's own message, and it names a position all the same. */
    public function test_naming_your_own_message_reads_what_came_before_it(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $waiting = $this->at($other, $viewer, 'theirs', 0);
        $mine = $this->at($viewer, $other, 'mine', 1);

        $this->report($viewer, $other, $mine->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($waiting, $viewer));
    }

    /** Read state is per receipt: the counterpart's copy of the same row is theirs to open. */
    public function test_reading_your_side_leaves_the_other_sides_receipt_alone(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $theirs = $this->at($viewer, $other, 'mine', 0);
        $mine = $this->at($other, $viewer, 'theirs', 1);

        $this->report($viewer, $other, $mine->getKey())->assertNoContent();

        $this->assertNull($this->readAt($theirs, $other));
    }

    /**
     * A trashed receipt is not on the screen to be read, and restoring it from the mailbox has to
     * hand back a message that has never been opened.
     */
    public function test_a_receipt_in_the_trash_is_not_read(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $trashed = $this->at($other, $viewer, 'trashed', 0, ['recipient_deleted_at' => now()]);
        $named = $this->at($other, $viewer, 'on screen', 1);

        $this->report($viewer, $other, $named->getKey())->assertNoContent();

        $this->assertNull($this->readAt($trashed, $viewer));
        $this->assertNotNull($this->readAt($named, $viewer));
    }

    /**
     * The report is scoped to the conversation it was made in, not to the reader's whole inbox:
     * another conversation's messages are older than this position too, and nobody has seen them.
     */
    public function test_reading_one_conversation_leaves_every_other_one_waiting(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $elsewhere = $this->at($third, $viewer, 'another conversation', 0);
        $fromNobody = $this->at(null, $viewer, 'from someone gone', 0);
        $named = $this->at($other, $viewer, 'here', 1);

        $this->report($viewer, $other, $named->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($named, $viewer));
        $this->assertNull($this->readAt($elsewhere, $viewer));
        $this->assertNull($this->readAt($fromNobody, $viewer), 'the withdrawn bucket is its own conversation');
    }

    /** And the other way round: the bucket everyone who left shares is not every conversation. */
    public function test_reading_the_withdrawn_bucket_leaves_a_members_conversation_waiting(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $theirs = $this->at($other, $viewer, 'from a member', 0);
        $named = $this->at(null, $viewer, 'from someone gone', 1);

        $this->report($viewer, null, $named->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($named, $viewer));
        $this->assertNull($this->readAt($theirs, $viewer));
    }

    public function test_a_message_of_another_conversation_is_refused(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $waiting = $this->at($other, $viewer, 'waiting', 0);
        $elsewhere = $this->at($third, $viewer, 'another conversation', 1);

        $this->report($viewer, $other, $elsewhere->getKey())->assertNotFound();

        $this->assertNull($this->readAt($waiting, $viewer));
        $this->assertNull($this->readAt($elsewhere, $viewer));
    }

    public function test_a_draft_names_no_position(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $waiting = $this->at($other, $viewer, 'waiting', 0);
        $draft = $this->deliver($other, $viewer, ['body' => 'never sent', 'is_draft' => true]);

        $this->report($viewer, $other, $draft->getKey())->assertNotFound();

        $this->assertNull($this->readAt($waiting, $viewer));
    }

    /** A row the viewer has trashed is out of their conversation, so it cannot name a position in it either. */
    public function test_a_message_the_viewer_has_trashed_names_no_position(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $trashed = $this->at($other, $viewer, 'trashed', 0, ['recipient_deleted_at' => now()]);

        $this->report($viewer, $other, $trashed->getKey())->assertNotFound();
    }

    public function test_a_missing_or_malformed_id_is_a_validation_error(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->postJson("/messages/{$other->getKey()}/read", [])
            ->assertJsonValidationErrorFor('messageId');

        $this->actingAs($viewer)
            ->postJson("/messages/{$other->getKey()}/read", ['messageId' => 'nope'])
            ->assertJsonValidationErrorFor('messageId');
    }

    /** Replaying a report — a retry, a second tab — leaves the first read time where it is. */
    public function test_replaying_a_report_changes_nothing(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $message = $this->at($other, $viewer, 'waiting', 0);

        $this->report($viewer, $other, $message->getKey())->assertNoContent();
        $first = $this->readAt($message, $viewer);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->report($viewer, $other, $message->getKey())->assertNoContent();

        $this->assertSame($first, $this->readAt($message, $viewer));
    }

    /**
     * Two tabs reporting out of order. `read_at` only ever goes from null to a time, so an older
     * report re-marks nothing and the boundary cannot walk backwards.
     */
    public function test_an_older_report_after_a_newer_one_marks_nothing(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $older = $this->at($other, $viewer, 'older', 0);
        $newer = $this->at($other, $viewer, 'newer', 1);

        $this->report($viewer, $other, $newer->getKey())->assertNoContent();
        $read = [$this->readAt($older, $viewer), $this->readAt($newer, $viewer)];

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->report($viewer, $other, $older->getKey())->assertNoContent();

        $this->assertSame($read, [$this->readAt($older, $viewer), $this->readAt($newer, $viewer)]);
    }

    /** Same second, different ids: the id half of the tuple decides what the position includes. */
    public function test_the_position_separates_two_messages_of_the_same_second(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $first = $this->at($other, $viewer, 'first', 0);
        $second = $this->at($other, $viewer, 'second', 0);

        $this->report($viewer, $other, $first->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($first, $viewer));
        $this->assertNull($this->readAt($second, $viewer));
    }

    public function test_the_withdrawn_bucket_is_read_by_its_own_name(): void
    {
        $viewer = Member::factory()->create();
        $waiting = $this->at(null, $viewer, 'from someone gone', 0);
        $named = $this->at(null, $viewer, 'the newest of them', 1);

        $this->report($viewer, null, $named->getKey())->assertNoContent();

        $this->assertNotNull($this->readAt($waiting, $viewer));
        $this->assertNotNull($this->readAt($named, $viewer));
    }

    /** A withdrawn sender's message is not in a named member's conversation, whoever they are. */
    public function test_a_withdrawn_senders_message_names_no_position_in_a_members_conversation(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $orphan = $this->at(null, $viewer, 'from someone gone', 0);

        $this->report($viewer, $other, $orphan->getKey())->assertNotFound();
    }

    /** The shell's badge counts conversations with something new, and reading one is what drops it. */
    public function test_the_nav_badge_falls_as_the_conversation_is_read(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, 'first', 0);
        $newest = $this->at($other, $viewer, 'second', 1);

        // Two messages, one conversation.
        $this->actingAs($viewer)->getJson('/unread-counts')->assertJsonPath('unread.unreadMessages', 1);

        $this->report($viewer, $other, $newest->getKey())->assertNoContent();

        // The aggregate memoizes per request, and one test method makes several through the same
        // container; without this the badge would be answered from the read above.
        $this->freshRequestState();
        $this->actingAs($viewer)->getJson('/unread-counts')->assertJsonPath('unread.unreadMessages', 0);
    }
}
