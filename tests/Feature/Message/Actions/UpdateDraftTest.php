<?php

namespace Tests\Feature\Message\Actions;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\Actions\UpdateDraft;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
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
        $recipient = $draft->recipients()->with('recipient')->first()->recipient;

        app(UpdateDraft::class)($sender, $draft, 'Subject', 'Body', asDraft: false);

        $this->assertFalse($draft->fresh()->is_draft);
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
}
