<?php

namespace Tests\Feature\Message\Classic;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
use App\Services\NavigationService;
use Database\Seeders\GadgetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessageComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_form_renders_for_another_member(): void
    {
        [$viewer, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)->get(route('message.compose', ['id' => $recipient->getKey()]))
            ->assertOk()
            ->assertSee('id="page_message_sendToFriend"', false)
            ->assertSee($recipient->name)
            ->assertSee('name="to"', false);
    }

    public function test_compose_to_self_is_404(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('message.compose', ['id' => $member->getKey()]))->assertNotFound();
    }

    public function test_sending_delivers_to_the_inbox_and_notifies(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)->post(route('message.compose.store'), [
            'to' => $recipient->getKey(),
            'subject' => 'Hello there',
            'body' => 'Body text',
            'action' => 'send',
        ])->assertRedirect(route('message.send'));

        $message = Message::firstOrFail();
        $this->assertFalse($message->is_draft);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);

        // It now appears in the recipient's inbox.
        $this->actingAs($recipient)->get(route('message.receive'))->assertOk()->assertSee('Hello there');
    }

    public function test_saving_a_draft_lands_in_the_draft_box_without_sending(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)->post(route('message.compose.store'), [
            'to' => $recipient->getKey(),
            'subject' => 'A draft',
            'body' => 'Later',
            'action' => 'draft',
        ])->assertRedirect(route('message.draft'));

        $this->assertTrue(Message::firstOrFail()->is_draft);
        Notification::assertNothingSent();
    }

    public function test_compose_requires_subject_and_body(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)->post(route('message.compose.store'), [
            'to' => $recipient->getKey(),
            'subject' => '',
            'body' => '',
            'action' => 'send',
        ])->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, Message::count());
    }

    public function test_sending_to_a_blocked_member_flashes_an_error(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        DB::table('member_blocks')->insert([
            'blocker_id' => $recipient->getKey(),
            'blocked_id' => $sender->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($sender)->post(route('message.compose.store'), [
            'to' => $recipient->getKey(),
            'subject' => 'Hi',
            'body' => 'Body',
            'action' => 'send',
        ])->assertRedirect(route('message.send'))->assertSessionHas('error');

        $this->assertSame(0, Message::count());
    }

    public function test_reply_prefills_the_thread_re_subject_and_quoted_body(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Original', "Line one\nLine two"), asDraft: false);

        // The recipient replies: form addresses the original sender, carries the thread links, and
        // prefills "Re:" subject + the body quoted line-by-line.
        $this->actingAs($recipient)->get(route('message.reply', ['message' => $original->getKey()]))
            ->assertOk()
            ->assertSee('value="'.$sender->getKey().'"', false)   // to = original sender
            ->assertSee('name="parent_id" value="'.$original->getKey().'"', false)
            ->assertSee('name="thread_id" value="'.$original->getKey().'"', false)
            ->assertSee('value="Re:Original"', false)             // Re: subject
            ->assertSee("> Line one\n> Line two");               // quoted body (escaped match)
    }

    public function test_only_the_recipient_may_reply(): void
    {
        Notification::fake();
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Original', 'Body'), asDraft: false);

        $this->actingAs($stranger)->get(route('message.reply', ['message' => $original->getKey()]))->assertNotFound();
    }

    public function test_reply_is_unavailable_once_the_received_message_leaves_the_inbox(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Original', 'Body'), asDraft: false);
        $receipt = $original->recipients()->firstOrFail();

        // Trashed: reply is an inbox action, so it is gone.
        $receipt->forceFill(['recipient_deleted_at' => now()])->save();
        $this->actingAs($recipient)->get(route('message.reply', ['message' => $original->getKey()]))->assertNotFound();

        // Purged: still gone — the body must not resurface as a quote.
        $receipt->forceFill(['recipient_purged_at' => now()])->save();
        $this->actingAs($recipient)->get(route('message.reply', ['message' => $original->getKey()]))->assertNotFound();
    }

    public function test_draft_edit_renders_for_the_owner_and_sends(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true);

        $this->actingAs($sender)->get(route('message.draft.edit', ['message' => $draft->getKey()]))
            ->assertOk()
            ->assertSee('id="page_message_edit"', false)
            ->assertSee('Draft');

        $this->actingAs($sender)->post(route('message.draft.update', ['message' => $draft->getKey()]), [
            'subject' => 'Draft',
            'body' => 'Body',
            'action' => 'send',
        ])->assertRedirect(route('message.send'));

        $this->assertFalse($draft->fresh()->is_draft);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_draft_edit_is_404_for_a_non_owner(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $draft = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true);

        $this->actingAs($stranger)->get(route('message.draft.edit', ['message' => $draft->getKey()]))->assertNotFound();
    }

    public function test_a_trashed_draft_cannot_be_edited_or_updated(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true);
        $draft->forceFill(['sender_deleted_at' => now()])->save(); // moved to trash

        $this->actingAs($sender)->get(route('message.draft.edit', ['message' => $draft->getKey()]))->assertNotFound();
        $this->actingAs($sender)->post(route('message.draft.update', ['message' => $draft->getKey()]), [
            'subject' => 'X', 'body' => 'Y', 'action' => 'draft',
        ])->assertNotFound();
    }

    public function test_draft_edit_rejects_exceeding_the_image_cap_with_a_validation_error(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $draft = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true, images: [
            UploadedFile::fake()->image('a.png', 20, 20),
            UploadedFile::fake()->image('b.png', 20, 20),
        ]);

        // 2 kept + 2 new = 4 > MAX(3): an ordinary add (no removals) is a validation error, not a 404.
        $this->actingAs($sender)->post(route('message.draft.update', ['message' => $draft->getKey()]), [
            'subject' => 'Draft', 'body' => 'Body', 'action' => 'draft',
            'images' => [
                UploadedFile::fake()->image('c.png', 20, 20),
                UploadedFile::fake()->image('d.png', 20, 20),
            ],
        ])->assertSessionHasErrors('images');
    }

    public function test_compose_is_reachable_from_the_friend_localnav_even_with_gadgets(): void
    {
        // The compose entry is the friend localNav "Send Message" row, shown on a page about another
        // member regardless of the profile's gadget configuration.
        $this->seed(NavigationSeeder::class);
        $this->seed(GadgetSeeder::class);
        app(NavigationService::class)->clearCache();

        [$viewer, $owner] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)->get(route('member.profile.show', $owner))
            ->assertOk()
            ->assertSee(route('message.compose', ['id' => $owner->getKey()]), false);
    }

    public function test_a_reply_link_to_a_message_the_viewer_is_not_party_to_is_rejected(): void
    {
        [$viewer, $other, $stranger] = Member::factory()->count(3)->create();
        // A message between two other members; the viewer is not a party to it.
        $foreign = app(SendMessage::class)($stranger, new MessageComposeData($other->getKey(), 'X', 'Y'), asDraft: false);

        $this->actingAs($viewer)->post(route('message.compose.store'), [
            'to' => $other->getKey(),
            'subject' => 'Hi', 'body' => 'Body', 'action' => 'send',
            'parent_id' => $foreign->getKey(),
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame(1, Message::count()); // nothing new sent
    }

    public function test_a_reply_link_to_a_received_message_is_accepted(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Orig', 'Body'), asDraft: false);

        // The recipient may reply, carrying the thread links to the message they received.
        $this->actingAs($recipient)->post(route('message.compose.store'), [
            'to' => $sender->getKey(),
            'subject' => 'Re:Orig', 'body' => 'Reply', 'action' => 'send',
            'parent_id' => $original->getKey(), 'thread_id' => $original->getKey(),
        ])->assertRedirect(route('message.send'));
    }
}
