<?php

namespace Tests\Feature\Message\Actions;

use App\Features\Message\Actions\PurgeMessages;
use App\Features\Message\Actions\RestoreMessages;
use App\Features\Message\Actions\TrashMessages;
use App\Features\Message\MessageBox;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrashRestorePurgeTest extends TestCase
{
    use RefreshDatabase;

    /** A delivered message: the sender's row plus the recipient's receipt. */
    private function delivered(Member $sender, Member $recipient): array
    {
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        $receipt = MessageRecipient::factory()->create([
            'message_id' => $message->getKey(),
            'recipient_id' => $recipient->getKey(),
        ]);

        return [$message, $receipt];
    }

    public function test_trash_moves_only_the_acting_side(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->assertSame(1, app(TrashMessages::class)($recipient, MessageBox::Receive, [$message->getKey()]));

        $this->assertNotNull($receipt->fresh()->recipient_deleted_at);
        $this->assertNull($message->fresh()->sender_deleted_at); // the sender's copy stays in their sent box
    }

    public function test_trashing_a_sent_message_sets_the_sender_side(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->assertSame(1, app(TrashMessages::class)($sender, MessageBox::Sent, [$message->getKey()]));

        $this->assertNotNull($message->fresh()->sender_deleted_at);
        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_trash_is_idempotent_and_keeps_the_first_moved_time(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        app(TrashMessages::class)($recipient, MessageBox::Receive, [$message->getKey()]);
        $firstMoved = $receipt->fresh()->recipient_deleted_at;

        // A second move touches nothing — the trash sorts on this column, so it must not bump.
        $this->assertSame(0, app(TrashMessages::class)($recipient, MessageBox::Receive, [$message->getKey()]));
        $this->assertEquals($firstMoved, $receipt->fresh()->recipient_deleted_at);
    }

    public function test_actions_are_scoped_to_the_viewer(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->assertSame(0, app(TrashMessages::class)($stranger, MessageBox::Receive, [$message->getKey()]));
        $this->assertSame(0, app(RestoreMessages::class)($stranger, [$message->getKey()]));
        $this->assertSame(0, app(PurgeMessages::class)($stranger, [$message->getKey()]));
    }

    public function test_restore_clears_only_the_acting_side(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $message->forceFill(['sender_deleted_at' => now()])->save();
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->assertSame(1, app(RestoreMessages::class)($recipient, [$message->getKey()]));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
        $this->assertNotNull($message->fresh()->sender_deleted_at); // the sender's trash is untouched
    }

    public function test_restore_does_not_revive_a_purged_side(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now(), 'recipient_purged_at' => now()])->save();

        $this->assertSame(0, app(RestoreMessages::class)($recipient, [$message->getKey()]));
        $this->assertNotNull($receipt->fresh()->recipient_deleted_at); // a purged receipt stays gone
    }

    public function test_purge_only_applies_to_a_trashed_row_and_keeps_purged_implies_deleted(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        // Purge never precedes trash: a live receipt is left untouched.
        $this->assertSame(0, app(PurgeMessages::class)($recipient, [$message->getKey()]));
        $this->assertNull($receipt->fresh()->recipient_purged_at);

        $receipt->forceFill(['recipient_deleted_at' => now()])->save();
        $this->assertSame(1, app(PurgeMessages::class)($recipient, [$message->getKey()]));

        $fresh = $receipt->fresh();
        $this->assertNotNull($fresh->recipient_purged_at);
        $this->assertNotNull($fresh->recipient_deleted_at); // invariant: purged ⇒ deleted
    }

    public function test_purge_is_per_side_and_keeps_the_row_and_counterpart_copy(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $message->forceFill(['sender_deleted_at' => now()])->save();
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        app(PurgeMessages::class)($sender, [$message->getKey()]);

        $this->assertNotNull($message->fresh()->sender_purged_at);
        $this->assertNull($receipt->fresh()->recipient_purged_at); // recipient's copy still readable
        // Nothing is physically removed: the row (and its file bytes) survive for the other side.
        $this->assertDatabaseHas('messages', ['id' => $message->getKey()]);
        $this->assertDatabaseHas('message_recipients', ['id' => $receipt->getKey()]);
    }

    public function test_bulk_covers_many_ids_at_once(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$a] = $this->delivered($sender, $recipient);
        [$b] = $this->delivered($sender, $recipient);

        $this->assertSame(2, app(TrashMessages::class)($recipient, MessageBox::Receive, [$a->getKey(), $b->getKey()]));
    }
}
