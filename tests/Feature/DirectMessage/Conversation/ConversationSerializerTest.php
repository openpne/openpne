<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessageFile;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Member;

class ConversationSerializerTest extends ConversationTestCase
{
    public function test_a_mailbox_subject_is_carried_and_a_chat_message_has_none(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['subject' => 'A friendly note', 'created_at' => now()->subMinute()]);
        $this->deliver($viewer, $other, ['subject' => null]);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertSame('A friendly note', $page['messages'][0]['subject']);
        $this->assertNull($page['messages'][1]['subject']);
    }

    /** The storage keeps null and '' distinct, so the wire does too; the screen draws neither. */
    public function test_an_empty_subject_is_carried_as_the_empty_string(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['subject' => '']);

        $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->assertJsonPath('messages.0.subject', '');
    }

    /** A legacy row may hold a null body (subject only), which is a message all the same. */
    public function test_a_null_body_serializes_as_the_empty_string(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['subject' => 'Subject only', 'body' => null]);

        $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->assertJsonPath('messages.0.body', '')
            ->assertJsonPath('messages.0.subject', 'Subject only');
    }

    public function test_the_viewers_own_messages_are_marked_and_the_others_are_not(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'mine', 'created_at' => now()->subMinute()]);
        $this->deliver($other, $viewer, ['body' => 'theirs']);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertTrue($page['messages'][0]['isOwn']);
        $this->assertSame($viewer->getKey(), $page['messages'][0]['author']['id']);
        $this->assertFalse($page['messages'][1]['isOwn']);
    }

    public function test_read_reports_only_on_the_viewers_own_messages(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'opened', 'created_at' => now()->subMinutes(3)], ['read_at' => now()]);
        $this->deliver($viewer, $other, ['body' => 'unopened', 'created_at' => now()->subMinutes(2)]);
        // A message the viewer received: reading it is what they are doing, so there is nothing to report.
        $this->deliver($other, $viewer, ['body' => 'theirs', 'created_at' => now()->subMinute()], ['read_at' => now()]);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertTrue($page['messages'][0]['read']);
        $this->assertFalse($page['messages'][1]['read']);
        $this->assertNull($page['messages'][2]['read']);
    }

    /**
     * An upgraded OpenPNE 3 send may carry several receipts, and each recipient reads it in their own
     * conversation — so the answer is this conversation's receipt, not whichever one comes first.
     */
    public function test_read_is_answered_by_the_receipt_of_the_conversation_being_read(): void
    {
        [$viewer, $opened, $unopened] = Member::factory()->count(3)->create();
        $message = $this->deliver($viewer, $opened, ['body' => 'to both'], ['read_at' => now()]);
        DirectMessageRecipient::factory()->create([
            'direct_message_id' => $message->getKey(),
            'recipient_id' => $unopened->getKey(),
        ]);

        $this->actingAs($viewer)
            ->getJson("/messages/{$opened->getKey()}/messages")
            ->assertJsonPath('messages.0.read', true);
        $this->actingAs($viewer)
            ->getJson("/messages/{$unopened->getKey()}/messages")
            ->assertJsonPath('messages.0.read', false);
    }

    /** No receipt in the withdrawn bucket names a member, so no delivery state can be attributed. */
    public function test_the_withdrawn_bucket_reports_no_read_state(): void
    {
        [$viewer, $leaving] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $leaving, ['body' => 'sent'], ['read_at' => now()]);
        $leaving->delete();

        $this->actingAs($viewer)
            ->getJson('/messages/withdrawn/messages')
            ->assertJsonPath('messages.0.read', null)
            ->assertJsonPath('messages.0.author.id', $viewer->getKey());
    }

    public function test_a_withdrawn_author_serializes_as_a_null_author(): void
    {
        [$viewer, $leaving] = Member::factory()->count(2)->create();
        $this->deliver($leaving, $viewer, ['body' => 'from someone who left']);
        $leaving->delete();

        $this->actingAs($viewer)
            ->getJson('/messages/withdrawn/messages')
            ->assertJsonPath('messages.0.author', null)
            ->assertJsonPath('messages.0.isOwn', false);
    }

    public function test_attachments_are_serialized_in_slot_order(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $message = $this->deliver($viewer, $other, ['body' => 'with pictures']);
        // Written out of order, so the ordering is the relation's rather than the insert's.
        foreach ([3, 1, 2] as $slot) {
            DirectMessageFile::factory()->create([
                'direct_message_id' => $message->getKey(),
                'file_id' => File::factory()->create(['name' => "slot-{$slot}.png"])->getKey(),
                'number' => $slot,
            ]);
        }

        $images = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->json('messages.0.images');

        $this->assertCount(3, $images);
        foreach ([1, 2, 3] as $i => $slot) {
            $this->assertStringContainsString("slot-{$slot}.png", $images[$i]['url']);
            $this->assertNotSame('', $images[$i]['thumbnailUrl']);
        }
    }

    public function test_an_attachment_carries_the_fit_sources_and_its_recorded_size(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $message = $this->deliver($viewer, $other, ['body' => 'with a picture']);
        $file = File::factory()->create(['type' => 'image/png', 'width' => 1600, 'height' => 900]);
        DirectMessageFile::factory()->create([
            'direct_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => 1,
        ]);

        $image = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->json('messages.0.images.0');

        $this->assertSame($file->thumbnailUrl(640, 640), $image['fitSources'][1]['url']);
        $this->assertSame($file->thumbnailUrl(600, 800, square: true), $image['cropSources']['tall'][1]['url']);
        $this->assertSame(1600, $image['width']);
        $this->assertSame(900, $image['height']);
    }

    public function test_every_message_carries_its_own_cursor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        foreach ($page['messages'] as $message) {
            $this->assertStringEndsWith('|'.$message['id'], $message['cursor']);
        }
    }
}
