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

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

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

        $this->get(route('message.draft.edit', $draft))->assertRedirect('/login');
        $this->post(route('message.receive.trash', $message))->assertRedirect('/login');
        $this->post(route('message.trash.restore', $message))->assertRedirect('/login');
        $this->post(route('message.trash.purge', $message))->assertRedirect('/login');
    }

    public function test_modern_draft_edit_renders_the_form_for_the_owner(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->get(route('message.draft.edit', $draft))
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

        $this->actingAs($stranger)->get(route('message.draft.edit', $draft))->assertNotFound();
    }

    public function test_modern_draft_update_sends_and_redirects_to_the_sent_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.draft.update', $draft), ['subject' => 'Draft', 'body' => 'Body', 'action' => 'send'])
            ->assertRedirect(route('message.send'));

        $this->assertFalse($draft->fresh()->is_draft);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_modern_draft_update_keeps_a_draft_and_redirects_to_the_draft_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.draft.update', $draft), ['subject' => 'Edited', 'body' => 'Body', 'action' => 'draft'])
            ->assertRedirect(route('message.draft'));

        $this->assertTrue($draft->fresh()->is_draft);
        $this->assertSame('Edited', $draft->fresh()->subject);
        Notification::assertNothingSent();
    }

    public function test_modern_trash_received_redirects_to_the_inbox(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)
            ->post(route('message.receive.trash', $message))
            ->assertRedirect(route('message.receive'));

        $this->assertNotNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_trash_sent_redirects_to_the_sent_box(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($sender)
            ->post(route('message.send.trash', $message))
            ->assertRedirect(route('message.send'));

        $this->assertNotNull($message->fresh()->sender_deleted_at);
    }

    public function test_modern_restore_redirects_to_the_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)
            ->post(route('message.trash.restore', $message))
            ->assertRedirect(route('message.trash'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_purge_removes_the_viewers_copy_and_redirects_to_the_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient);
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();

        $this->actingAs($recipient)
            ->post(route('message.trash.purge', $message))
            ->assertRedirect(route('message.trash'));

        $this->assertNotNull($receipt->fresh()->recipient_purged_at);
        $this->assertDatabaseHas('messages', ['id' => $message->getKey()]); // the sender's copy is untouched
    }

    public function test_modern_trash_404s_for_a_non_party(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        [$message] = $this->delivered($sender, $recipient);

        $this->actingAs($stranger)->post(route('message.receive.trash', $message))->assertNotFound();
        $this->actingAs($stranger)->post(route('message.send.trash', $message))->assertNotFound();
    }

    public function test_modern_only_serves_the_canonical_draft_edit_as_inertia(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = $this->draftTo($sender, $recipient);

        $this->actingAs($sender)
            ->get(route('message.draft.edit', $draft))
            ->assertInertia(fn ($page) => $page->component('message/edit'));
    }
}
