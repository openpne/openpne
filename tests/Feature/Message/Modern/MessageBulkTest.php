<?php

namespace Tests\Feature\Message\Modern;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageBulkTest extends TestCase
{
    use RefreshDatabase;

    /** A delivered message: the sender's row plus the recipient's receipt. */
    private function delivered(Member $sender, Member $recipient, array $receipt = []): array
    {
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        $r = MessageRecipient::factory()->create([...['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return [$message, $r];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->post(route('message.modern.bulk'), ['box' => 'receive', 'action' => 'delete', 'ids' => [1]])->assertRedirect('/login');
    }

    public function test_modern_bulk_trash_from_the_inbox_redirects_to_the_modern_inbox(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$a, $receiptA] = $this->delivered($sender, $recipient);
        [$b, $receiptB] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'receive', 'action' => 'delete', 'ids' => [$a->getKey(), $b->getKey()]])
            ->assertRedirect(route('message.modern.receive'));

        $this->assertNotNull($receiptA->fresh()->recipient_deleted_at);
        $this->assertNotNull($receiptB->fresh()->recipient_deleted_at);
    }

    public function test_modern_bulk_restore_from_the_trash_redirects_to_the_modern_trash(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [, $receipt] = $this->delivered($sender, $recipient, ['recipient_deleted_at' => now()]);

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'trash', 'action' => 'restore', 'ids' => [$receipt->message_id]])
            ->assertRedirect(route('message.modern.trash'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_bulk_purge_with_confirm_removes_the_viewers_copies(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient, ['recipient_deleted_at' => now()]);

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'trash', 'action' => 'purge', 'confirm' => true, 'ids' => [$message->getKey()]])
            ->assertRedirect(route('message.modern.trash'));

        $this->assertNotNull($receipt->fresh()->recipient_purged_at);
        $this->assertDatabaseHas('messages', ['id' => $message->getKey()]); // the sender's copy is untouched
    }

    public function test_modern_bulk_purge_without_confirm_purges_nothing_and_never_renders_a_blade(): void
    {
        // Modern confirms inline, so an unconfirmed purge is a client error: it redirects (not a
        // Classic confirm-page render) and leaves the trash untouched.
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient, ['recipient_deleted_at' => now()]);

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'trash', 'action' => 'purge', 'ids' => [$message->getKey()]])
            ->assertRedirect(route('message.modern.trash'));

        $this->assertNull($receipt->fresh()->recipient_purged_at);
    }

    public function test_modern_bulk_rejects_an_action_that_does_not_belong_to_the_box(): void
    {
        $recipient = Member::factory()->create();

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'receive', 'action' => 'purge', 'ids' => [1]])
            ->assertSessionHasErrors('action');
    }

    public function test_modern_bulk_with_no_selection_just_redirects(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [, $receipt] = $this->delivered($sender, $recipient);

        $this->actingAs($recipient)
            ->post(route('message.modern.bulk'), ['box' => 'receive', 'action' => 'delete', 'ids' => []])
            ->assertRedirect(route('message.modern.receive'));

        $this->assertNull($receipt->fresh()->recipient_deleted_at);
    }

    public function test_modern_only_canonical_bulk_purge_without_confirm_does_not_render_a_blade(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');
        [$sender, $recipient] = Member::factory()->count(2)->create();
        [$message, $receipt] = $this->delivered($sender, $recipient, ['recipient_deleted_at' => now()]);

        // The canonical bulk route resolves to Modern under modern_only, so it must redirect rather
        // than render the Classic confirm page.
        $this->actingAs($recipient)
            ->post(route('message.bulk'), ['box' => 'trash', 'action' => 'purge', 'ids' => [$message->getKey()]])
            ->assertRedirect(route('message.trash'));

        $this->assertNull($receipt->fresh()->recipient_purged_at);
    }
}
