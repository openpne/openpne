<?php

namespace Tests\Feature\Message\Actions;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\Exceptions\MessageActionException;
use App\Features\Message\Exceptions\MessageActionFailure;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    private function send(): SendMessage
    {
        return app(SendMessage::class);
    }

    public function test_sending_creates_a_delivered_message_with_a_receipt_and_notifies(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new MessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false);

        $this->assertFalse($message->is_draft);
        $this->assertSame($sender->getKey(), (int) $message->sender_id);
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $message->getKey(),
            'recipient_id' => $recipient->getKey(),
            'read_at' => null,
        ]);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_a_draft_is_unsent_carries_a_receipt_and_does_not_notify(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new MessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: true);

        $this->assertTrue($message->is_draft);
        $this->assertDatabaseHas('message_recipients', ['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
        Notification::assertNothingSent();
    }

    public function test_self_addressed_message_is_404(): void
    {
        $member = Member::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        ($this->send())($member, new MessageComposeData($member->getKey(), 'Hi', 'Hello'), asDraft: false);
    }

    public function test_sending_across_a_block_is_refused_but_a_draft_is_allowed(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $recipient->getKey(),
            'blocked_id' => $sender->getKey(),
            'created_at' => now(),
        ]);

        // A draft to a blocked member is fine (stays private).
        ($this->send())($sender, new MessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: true);

        // Sending is refused.
        try {
            ($this->send())($sender, new MessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false);
            $this->fail('Expected a CannotSend failure.');
        } catch (MessageActionException $e) {
            $this->assertSame(MessageActionFailure::CannotSend, $e->reason);
        }
        Notification::assertNothingSent();
    }

    public function test_attachments_are_stored_numbered_and_owned_by_the_message(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new MessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false, images: [
            UploadedFile::fake()->image('a.png', 20, 20),
            UploadedFile::fake()->image('b.png', 20, 20),
        ]);

        $this->assertSame([1, 2], $message->files()->pluck('number')->all());
        $file = $message->files()->with('file')->first()->file;
        $this->assertSame('message', $file->related_entity_type);
        $this->assertSame($message->getKey(), $file->related_entity_id);
    }
}
