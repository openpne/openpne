<?php

namespace Tests\Feature\DirectMessage\Actions;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\Actions\UpdateDraft;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Files\ImageEdit;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class UpdateDraftTest extends TestCase
{
    use RefreshDatabase;

    /** A draft authored by $sender to a fresh recipient, with $imageCount attachments. */
    private function draft(Member $sender, int $imageCount = 0): DirectMessage
    {
        $recipient = Member::factory()->create();
        $images = array_map(fn (int $i) => UploadedFile::fake()->image("img{$i}.png", 20, 20), range(1, max($imageCount, 0)));

        return app(SendDirectMessage::class)($sender, new DirectMessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true, images: array_slice($images, 0, $imageCount));
    }

    public function test_editing_text_keeps_it_a_draft_without_notifying(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);

        app(UpdateDraft::class)($sender, $draft, 'New subject', 'New body', asDraft: true, images: ImageEdit::none());

        $this->assertDatabaseHas('direct_messages', ['id' => $draft->getKey(), 'subject' => 'New subject', 'is_draft' => true]);
        Notification::assertNothingSent();
    }

    public function test_sending_a_draft_marks_it_sent_and_notifies(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $recipient = $draft->draftRecipient; // a draft holds its recipient here, not in a receipt

        app(UpdateDraft::class)($sender, $draft, 'Subject', 'Body', asDraft: false, images: ImageEdit::none());

        $this->assertFalse($draft->fresh()->is_draft);
        // Sending materializes the receipt and clears the draft-only column.
        $this->assertDatabaseHas('direct_message_recipients', ['direct_message_id' => $draft->getKey(), 'recipient_id' => $recipient->getKey()]);
        $this->assertNull($draft->fresh()->draft_recipient_id);
        Notification::assertSentTo($recipient, DirectMessageReceivedNotification::class);
    }

    public function test_image_slots_can_be_removed_and_added(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender, imageCount: 2); // slots 1, 2
        $removeId = $draft->files()->where('number', 1)->value('id');

        app(UpdateDraft::class)($sender, $draft, 'Subject', 'Body', asDraft: true,
            images: ImageEdit::of([UploadedFile::fake()->image('c.png', 20, 20)], [$removeId]));

        // Slot 2 stays; slot 1 freed then taken by the new upload.
        $this->assertEqualsCanonicalizing([1, 2], $draft->files()->pluck('number')->all());
        $this->assertDatabaseMissing('direct_message_files', ['id' => $removeId]);
    }

    public function test_a_non_owner_cannot_edit_the_draft(): void
    {
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $stranger = Member::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        app(UpdateDraft::class)($stranger, $draft, 'X', 'Y', asDraft: true, images: ImageEdit::none());
    }

    public function test_a_racing_second_send_does_not_duplicate_the_receipt(): void
    {
        Notification::fake();
        $sender = Member::factory()->create();
        $draft = $this->draft($sender);
        $stale = DirectMessage::findOrFail($draft->getKey()); // a second handle, still a draft in memory

        app(UpdateDraft::class)($sender, $draft, 'S', 'B', asDraft: false, images: ImageEdit::none()); // first send delivers it
        $this->assertSame(1, DirectMessageRecipient::where('direct_message_id', $draft->getKey())->count());

        // The racing send works the stale handle; under the lock it re-reads a non-draft and aborts.
        try {
            app(UpdateDraft::class)($sender, $stale, 'S', 'B', asDraft: false, images: ImageEdit::none());
            $this->fail('Expected the stale concurrent send to abort.');
        } catch (NotFoundHttpException) {
            // expected
        }
        $this->assertSame(1, DirectMessageRecipient::where('direct_message_id', $draft->getKey())->count());
    }
}
