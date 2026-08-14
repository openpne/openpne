<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\Actions\DeleteConversation;
use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/**
 * Deleting a conversation: everything it holds leaves the viewer's screens at once, and nothing of
 * the counterpart's moves.
 */
class DeleteConversationTest extends ConversationTestCase
{
    private function deleteConversation(Member $viewer, ?Member $counterpart): int
    {
        return app(DeleteConversation::class)($viewer, $counterpart);
    }

    public function test_both_arms_of_the_conversation_are_trashed_and_purged_at_once(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $sent = $this->deliver($viewer, $other);
        $received = $this->deliver($other, $viewer);

        $this->assertSame(2, $this->deleteConversation($viewer, $other));

        $sent->refresh();
        $this->assertNotNull($sent->sender_deleted_at);
        $this->assertNotNull($sent->sender_purged_at);

        $receipt = DirectMessageRecipient::where('direct_message_id', $received->getKey())->sole();
        $this->assertNotNull($receipt->recipient_deleted_at);
        $this->assertNotNull($receipt->recipient_purged_at);
    }

    /** Per-side and nothing else: the other member's copies of the same rows are where they were. */
    public function test_the_counterparts_own_side_is_untouched(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $sent = $this->deliver($viewer, $other);
        $received = $this->deliver($other, $viewer);

        $this->deleteConversation($viewer, $other);

        // The counterpart's receipt of what the viewer sent them.
        $theirReceipt = DirectMessageRecipient::where('direct_message_id', $sent->getKey())->sole();
        $this->assertNull($theirReceipt->recipient_deleted_at);
        $this->assertNull($theirReceipt->recipient_purged_at);

        // And their authored copy of what they sent the viewer.
        $received->refresh();
        $this->assertNull($received->sender_deleted_at);
        $this->assertNull($received->sender_purged_at);

        // Every row survives: this moves timestamps, it deletes nothing.
        $this->assertSame(2, DirectMessage::count());
        $this->assertSame(2, DirectMessageRecipient::count());
    }

    /** A draft belongs to no conversation, so emptying one leaves it in the drafts box. */
    public function test_a_draft_is_not_in_the_conversation(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $draft = DirectMessage::factory()->draft()->create([
            'sender_id' => $viewer->getKey(),
            'draft_recipient_id' => $other->getKey(),
        ]);

        $this->assertSame(0, $this->deleteConversation($viewer, $other));

        $draft->refresh();
        $this->assertNull($draft->sender_deleted_at);
        $this->assertNull($draft->sender_purged_at);
    }

    /**
     * The belt ConversationScope states as `is_draft = false`: a draft that somehow carries a receipt
     * is still the drafts box's, and the delete must not reach it either.
     */
    public function test_a_stray_receipt_does_not_put_a_draft_in_the_conversation(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $draft = $this->deliver($viewer, $other, ['is_draft' => true, 'draft_recipient_id' => $other->getKey()]);
        $inbound = $this->deliver($other, $viewer, ['is_draft' => true]);

        $this->assertSame(0, $this->deleteConversation($viewer, $other));

        $draft->refresh();
        $this->assertNull($draft->sender_deleted_at);
        $this->assertNull($draft->sender_purged_at);

        $receipt = DirectMessageRecipient::where('direct_message_id', $inbound->getKey())->sole();
        $this->assertNull($receipt->recipient_deleted_at);
        $this->assertNull($receipt->recipient_purged_at);
    }

    /** The delete is scoped to one counterpart; what the viewer holds of anyone else stays whole. */
    public function test_another_conversation_is_left_alone(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $sentToThird = $this->deliver($viewer, $third);
        $fromThird = $this->deliver($third, $viewer);
        $this->deliver($viewer, $other);

        $this->deleteConversation($viewer, $other);

        $sentToThird->refresh();
        $this->assertNull($sentToThird->sender_deleted_at);
        $this->assertNull($sentToThird->sender_purged_at);

        $receipt = DirectMessageRecipient::where('direct_message_id', $fromThird->getKey())->sole();
        $this->assertNull($receipt->recipient_deleted_at);
        $this->assertNull($receipt->recipient_purged_at);
    }

    /** Idempotent: a row already gone from the viewer's side keeps the time it went. */
    public function test_an_already_purged_row_keeps_its_first_purge_time(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $earlier = Carbon::parse('2026-08-01 09:00:00');
        $sent = $this->deliver($viewer, $other, [
            'sender_deleted_at' => $earlier,
            'sender_purged_at' => $earlier,
        ]);
        $received = $this->deliver($other, $viewer, [], [
            'recipient_deleted_at' => $earlier,
            'recipient_purged_at' => $earlier,
        ]);

        $this->assertSame(0, $this->deleteConversation($viewer, $other));

        $this->assertTrue($earlier->equalTo($sent->refresh()->sender_purged_at));
        $receipt = DirectMessageRecipient::where('direct_message_id', $received->getKey())->sole();
        $this->assertTrue($earlier->equalTo($receipt->recipient_purged_at));
    }

    /** A row in the mailbox's trash is not on the chat screen, so the conversation does not hold it. */
    public function test_a_row_the_viewer_has_only_trashed_stays_trashed(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $trashed = Carbon::parse('2026-08-01 09:00:00');
        $sent = $this->deliver($viewer, $other, ['sender_deleted_at' => $trashed]);

        $this->assertSame(0, $this->deleteConversation($viewer, $other));

        $sent->refresh();
        $this->assertTrue($trashed->equalTo($sent->sender_deleted_at));
        $this->assertNull($sent->sender_purged_at, 'the trash is where it can still be restored from');
    }

    public function test_deleting_twice_moves_nothing_the_second_time(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other);
        $this->deliver($other, $viewer);

        $this->assertSame(2, $this->deleteConversation($viewer, $other));
        $this->assertSame(0, $this->deleteConversation($viewer, $other));
    }

    /** Everyone whose account is gone is one conversation, and it is deleted as one. */
    public function test_the_withdrawn_bucket_is_deleted_as_one_conversation(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();
        $sent = $this->deliver($viewer, null);
        $received = $this->deliver(null, $viewer);
        $keep = $this->deliver($other, $viewer);

        $this->assertSame(2, $this->deleteConversation($viewer, null));

        $this->assertNotNull($sent->refresh()->sender_purged_at);
        $this->assertNotNull(
            DirectMessageRecipient::where('direct_message_id', $received->getKey())->sole()->recipient_purged_at,
        );
        $this->assertNull(
            DirectMessageRecipient::where('direct_message_id', $keep->getKey())->sole()->recipient_purged_at,
        );
    }

    public function test_the_screens_and_the_badge_answer_the_delete(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'mine']);
        $this->deliver($other, $viewer, ['body' => 'theirs']);

        $this->actingAs($viewer)->post("/messages/{$other->getKey()}/delete")
            ->assertRedirect('/messages')
            ->assertSessionHas('status');

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('message/conversations/index')
                ->has('conversations.data', 0)
                ->where('unread.unreadMessages', 0));

        $this->actingAs($viewer)->get("/messages/{$other->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('page.messages', 0));

        // The counterpart still has the whole conversation.
        $this->actingAs($other)->get("/messages/{$viewer->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('page.messages', 2));
    }

    /**
     * The chat semantics a deleted conversation is chosen for: it is gone until there is something
     * new in it, and then it holds only what arrived.
     */
    public function test_a_new_message_brings_the_conversation_back_with_only_itself_in_it(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'before', 'created_at' => Carbon::parse('2026-08-01 09:00:00')]);

        $this->deleteConversation($viewer, $other);

        $this->deliver($other, $viewer, ['body' => 'after', 'created_at' => Carbon::parse('2026-08-02 09:00:00')]);

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.latest.body', 'after')
                ->where('unread.unreadMessages', 1));

        $this->actingAs($viewer)->get("/messages/{$other->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('page.messages', 1)
                ->where('page.messages.0.body', 'after'));
    }

    /**
     * Purge revokes the purging side's view of an attachment and nothing more: the row and the bytes
     * stay for the other side (FilePolicy / DirectMessageAccess::canViewMessage).
     */
    public function test_an_attachment_survives_for_the_side_that_did_not_delete(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = app(SendDirectMessage::class)(
            $sender,
            new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Body'),
            asDraft: false,
            images: [UploadedFile::fake()->image('a.png', 20, 20)],
        );
        $file = $message->files()->with('file')->first()->file;

        $this->deleteConversation($recipient, $sender);

        $this->assertTrue($file->exists(), 'the File row is untouched — a purge moves timestamps only');
        $this->assertTrue(Gate::forUser($sender)->allows('view', $file));
        $this->assertFalse(Gate::forUser($recipient)->allows('view', $file));
        $this->actingAs($sender)->get(route('file.show', ['file' => $file->name]))->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $other = Member::factory()->create();

        $this->post("/messages/{$other->getKey()}/delete")->assertRedirect('/login');
        $this->post('/messages/withdrawn/delete')->assertRedirect('/login');
    }

    public function test_the_unit_switched_off_takes_both_delete_routes(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, false);

        $this->actingAs($viewer)->post("/messages/{$other->getKey()}/delete")->assertNotFound();
        $this->actingAs($viewer)->post('/messages/withdrawn/delete')->assertNotFound();
    }

    /** There is no conversation with yourself, so there is none to delete either. */
    public function test_deleting_a_conversation_with_yourself_is_refused(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->post("/messages/{$viewer->getKey()}/delete")->assertNotFound();
    }

    /** Nothing to delete is not an error: the member asked for the conversation to be gone, and it is. */
    public function test_an_empty_conversation_still_answers_the_list(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)->post("/messages/{$other->getKey()}/delete")->assertRedirect('/messages');
        $this->actingAs($viewer)->post('/messages/withdrawn/delete')->assertRedirect('/messages');
    }
}
