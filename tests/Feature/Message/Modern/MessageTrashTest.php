<?php

namespace Tests\Feature\Message\Modern;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    private function draftTo(Member $sender, Member $recipient): Message
    {
        return app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message] = $this->delivered($sender, $recipient);
        $draft = $this->draftTo($sender, $recipient);

        $this->get(route('message.modern.draft.edit', $draft))->assertRedirect('/login');
        $this->post(route('message.modern.receive.trash', $message))->assertRedirect('/login');
        $this->post(route('message.modern.trash.restore', $message))->assertRedirect('/login');
        $this->post(route('message.modern.trash.purge', $message))->assertRedirect('/login');
    }

    public function test_modern_draft_edit_renders_the_form_for_the_owner(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->get(route('message.modern.draft.edit', $draft))
            ->assertInertia(fn ($page) => $page
                ->component('message/edit')
                ->where('draft.id', $draft->getKey())
                ->where('draft.subject', 'Draft')
                ->where('draft.body', 'Body')
                ->where('draft.recipient.id', $recipient->getKey())
            );
    }

    public function test_modern_draft_edit_404s_for_a_non_owner(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($stranger)->get(route('message.modern.draft.edit', $draft))->assertNotFound();
    }

    public function test_modern_draft_update_sends_and_redirects_to_the_modern_sent_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.modern.draft.update', $draft), ['subject' => 'Draft', 'body' => 'Body', 'action' => 'send'])
            ->assertRedirect(route('message.modern.send'));

        $this->assertFalse($draft->fresh()->is_draft);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_modern_draft_update_keeps_a_draft_and_redirects_to_the_modern_draft_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.modern.draft.update', $draft), ['subject' => 'Edited', 'body' => 'Body', 'action' => 'draft'])
            ->assertRedirect(route('message.modern.draft'));

        $this->assertTrue($draft->fresh()->is_draft);
        $this->assertSame('Edited', $draft->fresh()->subject);
        Notification::assertNothingSent();
    }

    public function test_modern_trash_received_redirects_to_the_modern_inbox(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)
            ->post(route('message.modern.receive.trash', $message))
            ->assertRedirect(route('message.modern.receive'));

        $this->assertNotNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_trash_sent_redirects_to_the_modern_sent_box(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.modern.send.trash', $message))
            ->assertRedirect(route('message.modern.send'));

        $this->assertNotNull($message->fresh()->sender_deleted_at);
    }

    public function test_modern_restore_redirects_to_the_modern_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)
            ->post(route('message.modern.trash.restore', $message))
            ->assertRedirect(route('message.modern.trash'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_purge_removes_the_viewers_copy_and_redirects_to_the_modern_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)
            ->post(route('message.modern.trash.purge', $message))
            ->assertRedirect(route('message.modern.trash'));

        $this->assertNotNull($receipt->fresh()->recipient_purged_at);
        $this->assertDatabaseHas('messages', ['id' => $message->getKey()]); // the sender's copy is untouched
    }

    public function test_modern_trash_404s_for_a_non_party(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($stranger)->post(route('message.modern.receive.trash', $message))->assertNotFound();
        $this->actingAs($stranger)->post(route('message.modern.send.trash', $message))->assertNotFound();
    }

    public function test_modern_only_serves_the_canonical_draft_edit_as_inertia(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->get(route('message.draft.edit', $draft))
            ->assertInertia(fn ($page) => $page->component('message/edit'));
    }
}
