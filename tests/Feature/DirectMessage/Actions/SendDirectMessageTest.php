<?php

namespace Tests\Feature\DirectMessage\Actions;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Exceptions\DirectMessageActionFailure;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SendDirectMessageTest extends TestCase
{
    use RefreshDatabase;

    private function send(): SendDirectMessage
    {
        return app(SendDirectMessage::class);
    }

    public function test_sending_creates_a_delivered_message_with_a_receipt_and_notifies(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false);

        $this->assertFalse($message->is_draft);
        $this->assertSame($sender->getKey(), (int) $message->sender_id);
        $this->assertDatabaseHas('direct_message_recipients', [
            'direct_message_id' => $message->getKey(),
            'recipient_id' => $recipient->getKey(),
            'read_at' => null,
        ]);
        Notification::assertSentTo($recipient, DirectMessageReceivedNotification::class);
    }

    public function test_a_draft_holds_its_recipient_without_a_receipt_and_does_not_notify(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: true);

        $this->assertTrue($message->is_draft);
        // A draft is not delivered: the recipient sits on the row, with no receipt to surface it.
        $this->assertSame($recipient->getKey(), (int) $message->draft_recipient_id);
        $this->assertDatabaseMissing('direct_message_recipients', ['direct_message_id' => $message->getKey()]);
        Notification::assertNothingSent();
    }

    public function test_self_addressed_message_is_404(): void
    {
        $member = Member::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        ($this->send())($member, new DirectMessageComposeData($member->getKey(), 'Hi', 'Hello'), asDraft: false);
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
        ($this->send())($sender, new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: true);

        // Sending is refused.
        try {
            ($this->send())($sender, new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false);
            $this->fail('Expected a CannotSend failure.');
        } catch (DirectMessageActionException $e) {
            $this->assertSame(DirectMessageActionFailure::CannotSend, $e->reason);
        }
        Notification::assertNothingSent();
    }

    public function test_attachments_are_stored_numbered_and_owned_by_the_message(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $message = ($this->send())($sender, new DirectMessageComposeData($recipient->getKey(), 'Hi', 'Hello'), asDraft: false, images: [
            UploadedFile::fake()->image('a.png', 20, 20),
            UploadedFile::fake()->image('b.png', 20, 20),
        ]);

        $this->assertSame([1, 2], $message->files()->pluck('number')->all());
        $file = $message->files()->with('file')->first()->file;
        $this->assertSame('directMessage', $file->related_entity_type);
        $this->assertSame($message->getKey(), $file->related_entity_id);
    }
}
