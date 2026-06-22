<?php

namespace Tests\Feature\Message\Classic;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTrashTest extends TestCase
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

    public function test_show_offers_delete_and_trashing_a_received_message_redirects_to_the_inbox(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertSee(route('message.receive.trash', ['message' => $message->getKey()]), false);

        $this->actingAs($recipient)->post(route('message.receive.trash', ['message' => $message->getKey()]))
            ->assertRedirect(route('message.receive'));

        $this->assertNotNull($receipt->fresh()->recipient_deleted_at);
        // Gone from the inbox, now in the trash.
        $this->actingAs($recipient)->get(route('message.receive'))->assertOk()->assertDontSee($message->subject);
        $this->actingAs($recipient)->get(route('message.trash'))->assertOk()->assertSee($message->subject);
    }

    public function test_trashing_a_sent_message_moves_the_sender_side(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($sender)->post(route('message.send.trash', ['message' => $message->getKey()]))
            ->assertRedirect(route('message.send'));

        $this->assertNotNull($message->fresh()->sender_deleted_at);
    }

    public function test_trashing_a_message_you_are_not_a_party_to_is_404(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($stranger)->post(route('message.receive.trash', ['message' => $message->getKey()]))->assertNotFound();
        $this->actingAs($stranger)->post(route('message.send.trash', ['message' => $message->getKey()]))->assertNotFound();
    }

    public function test_purge_confirm_renders_for_a_trashed_message_and_404s_otherwise(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        // Not in the trash yet: no confirm page.
        $this->actingAs($recipient)->get(route('message.trash.purge.confirm', ['message' => $message->getKey()]))->assertNotFound();

        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)->get(route('message.trash.purge.confirm', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertSee('id="page_message_deleteConfirm"', false)
            ->assertSee(route('message.trash.purge', ['message' => $message->getKey()]), false);
    }

    public function test_purge_removes_the_viewers_copy_but_keeps_the_counterpart(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)->post(route('message.trash.purge', ['message' => $message->getKey()]))
            ->assertRedirect(route('message.trash'));

        $this->assertNotNull($receipt->fresh()->recipient_purged_at);
        // Gone from the recipient's trash; the sender's copy is untouched.
        $this->actingAs($recipient)->get(route('message.trash'))->assertOk()->assertDontSee($message->subject);
        $this->assertDatabaseHas('messages', ['id' => $message->getKey()]);
    }

    public function test_restore_returns_a_message_to_its_box(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)->post(route('message.trash.restore', ['message' => $message->getKey()]))
            ->assertRedirect(route('message.trash'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
        $this->actingAs($recipient)->get(route('message.receive'))->assertOk()->assertSee($message->subject);
    }

    public function test_bulk_trash_from_the_inbox(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$a, $receiptA] = $this->delivered($sender, $recipient);
        [$b, $receiptB] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'receive',
            'action' => 'delete',
            'ids' => [$a->getKey(), $b->getKey()],
        ])->assertRedirect(route('message.receive'));

        $this->assertNotNull($receiptA->fresh()->recipient_deleted_at);
        $this->assertNotNull($receiptB->fresh()->recipient_deleted_at);
    }

    public function test_bulk_restore_from_the_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'trash',
            'action' => 'restore',
            'ids' => [$message->getKey()],
        ])->assertRedirect(route('message.trash'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_bulk_purge_confirms_first_then_purges(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        // Step 1: the unconfirmed submit renders the confirmation, carrying the ids; nothing purged.
        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'trash',
            'action' => 'purge',
            'ids' => [$message->getKey()],
        ])->assertOk()->assertSee('name="ids[]" value="'.$message->getKey().'"', false);
        $this->assertNull($receipt->fresh()->recipient_purged_at);

        // Step 2: the confirmed submit purges.
        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'trash',
            'action' => 'purge',
            'confirm' => '1',
            'ids' => [$message->getKey()],
        ])->assertRedirect(route('message.trash'));
        $this->assertNotNull($receipt->fresh()->recipient_purged_at);
    }

    public function test_bulk_rejects_an_action_that_does_not_belong_to_the_box(): void
    {
        $recipient = Member::factory()->create();

        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'receive',
            'action' => 'purge',
            'ids' => [1],
        ])->assertSessionHasErrors('action');
    }

    public function test_bulk_with_no_selection_just_redirects(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)->post(route('message.bulk'), [
            'box' => 'receive',
            'action' => 'delete',
            'ids' => [],
        ])->assertRedirect(route('message.receive'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_a_draft_recipient_cannot_trash_or_view_the_unsent_draft(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        // A draft carries a receipt for its intended recipient, but the draft is the sender's alone.
        $draft = Message::factory()->draft()->create([
            'sender_id' => $sender->getKey(),
            'subject' => 'UNSENT-DRAFT-SUBJECT',
            'body' => 'UNSENT-DRAFT-BODY',
        ]);
        $receipt = MessageRecipient::factory()->create([
            'message_id' => $draft->getKey(),
            'recipient_id' => $recipient->getKey(),
        ]);

        // The intended recipient cannot move the unsent draft to their trash.
        $this->actingAs($recipient)->post(route('message.receive.trash', ['message' => $draft->getKey()]))->assertNotFound();
        $this->assertNull($receipt->fresh()->recipient_deleted_at);

        // Even a stray trashed receipt (e.g. bad imported data) must not surface the draft.
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();
        $this->actingAs($recipient)->get(route('message.trash'))->assertOk()
            ->assertDontSee('UNSENT-DRAFT-SUBJECT');
        $this->actingAs($recipient)->get(route('message.trash.show', ['message' => $draft->getKey()]))->assertNotFound();
    }

    public function test_bulk_trash_does_not_cross_boxes(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$sent] = $this->delivered($sender, $recipient);
        $draft = Message::factory()->draft()->create(['sender_id' => $sender->getKey()]);

        // The draft box only trashes drafts: a sent id submitted there is ignored.
        $this->actingAs($sender)->post(route('message.bulk'), ['box' => 'draft', 'action' => 'delete', 'ids' => [$sent->getKey()]])
            ->assertRedirect(route('message.draft'));
        $this->assertNull($sent->fresh()->sender_deleted_at);

        // The sent box only trashes sent messages: a draft id submitted there is ignored.
        $this->actingAs($sender)->post(route('message.bulk'), ['box' => 'sent', 'action' => 'delete', 'ids' => [$draft->getKey()]])
            ->assertRedirect(route('message.send'));
        $this->assertNull($draft->fresh()->sender_deleted_at);
    }
}
