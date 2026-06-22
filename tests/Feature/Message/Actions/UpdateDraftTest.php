<?php

namespace Tests\Feature\Message\Actions;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\Actions\UpdateDraft;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class UpdateDraftTest extends TestCase
{
    use RefreshDatabase;

    /** A draft authored by $sender to a fresh recipient, with $imageCount attachments. */
    private function draft(Member $sender, int $imageCount = 0): Message
    {
        $recipient = Member::factory()->create();
        $images = array_map(fn (int $i) => UploadedFile::fake()->image("img{$i}.png", 20, 20), range(1, max($imageCount, 0)));

        return app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true, images: array_slice($images, 0, $imageCount));
    }

    public function test_editing_text_keeps_it_a_draft_without_notifying(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);

        app(UpdateDraft::class)($sender, $draft, 'New subject', 'New body', asDraft: true);

        $this->assertDatabaseHas('messages', ['id' => $draft->getKey(), 'subject' => 'New subject', 'is_draft' => true]);
        Notification::assertNothingSent();
    }

    public function test_sending_a_draft_marks_it_sent_and_notifies(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $recipient = $draft->draftRecipient; // a draft holds its recipient here, not in a receipt

        app(UpdateDraft::class)($sender, $draft, 'Subject', 'Body', asDraft: false);

        $this->assertFalse($draft->fresh()->is_draft);
        // Sending materializes the receipt and clears the draft-only column.
        $this->assertDatabaseHas('message_recipients', ['message_id' => $draft->getKey(), 'recipient_id' => $recipient->getKey()]);
        $this->assertNull($draft->fresh()->draft_recipient_id);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_image_slots_can_be_removed_and_added(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender, imageCount: 2); // slots 1, 2
        $removeId = $draft->files()->where('number', 1)->value('id');

        app(UpdateDraft::class)($sender, $draft, 'Subject', 'Body', asDraft: true,
            newImages: [UploadedFile::fake()->image('c.png', 20, 20)],
            removeImageIds: [$removeId]);

        // Slot 2 stays; slot 1 freed then taken by the new upload.
        $this->assertEqualsCanonicalizing([1, 2], $draft->files()->pluck('number')->all());
        $this->assertDatabaseMissing('message_files', ['id' => $removeId]);
    }

    public function test_a_non_owner_cannot_edit_the_draft(): void
    {
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $stranger = Member::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        app(UpdateDraft::class)($stranger, $draft, 'X', 'Y', asDraft: true);
    }

    public function test_a_racing_second_send_does_not_duplicate_the_receipt(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $stale = Message::findOrFail($draft->getKey()); // a second handle, still a draft in memory

        app(UpdateDraft::class)($sender, $draft, 'S', 'B', asDraft: false); // first send delivers it
        $this->assertSame(1, MessageRecipient::where('message_id', $draft->getKey())->count());

        // The racing send works the stale handle; under the lock it re-reads a non-draft and aborts.
        try {
            app(UpdateDraft::class)($sender, $stale, 'S', 'B', asDraft: false);
            $this->fail('Expected the stale concurrent send to abort.');
        } catch (NotFoundHttpException) {
            // expected
        }
        $this->assertSame(1, MessageRecipient::where('message_id', $draft->getKey())->count());
    }

    public function test_a_message_cannot_carry_two_receipts_for_one_recipient(): void
    {
        [$s, $r] = Member::factory()->count(2)->create();
        $m = Message::factory()->create(['sender_id' => $s->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $m->getKey(), 'recipient_id' => $r->getKey()]);

        // The unique index backstops the delivery idempotency the lock already enforces.
        $this->expectException(QueryException::class);
        MessageRecipient::factory()->create(['message_id' => $m->getKey(), 'recipient_id' => $r->getKey()]);
    }
}
