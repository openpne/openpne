<?php

namespace Tests\Feature\Message;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\MessageComposeData;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A message attachment inherits the message's privacy through FilePolicy: only the sender and a
 * recipient of a delivered (non-draft) message may fetch its bytes.
 */
class MessageAttachmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: File, 1: Member, 2: Member} [attachment file, sender, recipient] */
    private function deliveredWithImage(bool $draft = false): array
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = app(SendMessage::class)(
            $sender,
            new MessageComposeData($recipient->getKey(), 'Hi', 'Body'),
            asDraft: $draft,
            images: [UploadedFile::fake()->image('a.png', 20, 20)],
        );

        return [$message->files()->with('file')->first()->file, $sender, $recipient];
    }

    public function test_sender_and_recipient_may_view_a_delivered_attachment(): void
    {
        [$file, $sender, $recipient] = $this->deliveredWithImage();

        $this->assertTrue(Gate::forUser($sender)->allows('view', $file));
        $this->assertTrue(Gate::forUser($recipient)->allows('view', $file));
    }

    public function test_a_draft_attachment_is_hidden_from_the_recipient_but_not_the_sender(): void
    {
        [$file, $sender, $recipient] = $this->deliveredWithImage(draft: true);

        $this->assertTrue(Gate::forUser($sender)->allows('view', $file));
        $this->assertFalse(Gate::forUser($recipient)->allows('view', $file));
    }

    public function test_a_third_party_may_not_view_the_attachment(): void
    {
        [$file] = $this->deliveredWithImage();
        $stranger = Member::factory()->create();

        $this->assertFalse(Gate::forUser($stranger)->allows('view', $file));
        // Refused through the gated file route — 404, not 403, so the response does not confirm it exists.
        $this->actingAs($stranger)->get(route('file.show', ['file' => $file->name]))->assertNotFound();
    }
}
