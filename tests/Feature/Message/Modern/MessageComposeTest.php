<?php

namespace Tests\Feature\Message\Modern;

use App\Features\Message\Actions\SendMessage;
use App\Features\Message\MessageComposeData;
use App\Models\Member;
use App\Models\Message;
use App\Notifications\Message\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessageComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'X', 'Y'), asDraft: false);

        $this->get(route('message.modern.compose', ['id' => $recipient->getKey()]))->assertRedirect('/login');
        $this->get(route('message.modern.reply', $message))->assertRedirect('/login');
        $this->post(route('message.modern.compose.store'))->assertRedirect('/login');
    }

    public function test_modern_compose_renders_the_form_for_a_recipient(): void
    {
        [$viewer, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->get(route('message.modern.compose', ['id' => $recipient->getKey()]))
            ->assertInertia(fn ($page) => $page
                ->component('message/compose')
                ->where('recipient.id', $recipient->getKey())
                ->where('parentId', null)
                ->where('threadId', null)
                ->where('subject', '')
                ->where('body', '')
            );
    }

    public function test_modern_compose_404s_for_self_or_a_missing_recipient(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('message.modern.compose', ['id' => $member->getKey()]))->assertNotFound();
        $this->actingAs($member)->get(route('message.modern.compose', ['id' => 999999]))->assertNotFound();
    }

    public function test_modern_reply_prefills_the_thread_and_quoted_body(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Original', "Line one\nLine two"), asDraft: false);

        $this->actingAs($recipient)
            ->get(route('message.modern.reply', $original))
            ->assertInertia(fn ($page) => $page
                ->component('message/compose')
                ->where('recipient.id', $sender->getKey())
                ->where('parentId', $original->getKey())
                ->where('threadId', $original->getKey())
                ->where('subject', 'Re:Original')
                ->where('body', "> Line one\n> Line two")
            );
    }

    public function test_modern_reply_404s_for_a_non_recipient(): void
    {
        Notification::fake();
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $original = app(SendMessage::class)($sender, new MessageComposeData($recipient->getKey(), 'Original', 'Body'), asDraft: false);

        $this->actingAs($stranger)->get(route('message.modern.reply', $original))->assertNotFound();
    }

    public function test_modern_store_sends_and_redirects_to_the_modern_sent_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.modern.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Hello there',
                'body' => 'Body text',
                'action' => 'send',
            ])
            ->assertRedirect(route('message.modern.send'));

        $this->assertFalse(Message::firstOrFail()->is_draft);
        Notification::assertSentTo($recipient, MessageReceivedNotification::class);
    }

    public function test_modern_store_saves_a_draft_and_redirects_to_the_modern_draft_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.modern.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'A draft',
                'body' => 'Later',
                'action' => 'draft',
            ])
            ->assertRedirect(route('message.modern.draft'));

        $this->assertTrue(Message::firstOrFail()->is_draft);
        Notification::assertNothingSent();
    }

    public function test_modern_store_flashes_an_error_when_the_send_is_blocked(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $recipient->forceFill(['is_login_rejected' => true])->save(); // a banned member cannot receive

        $this->actingAs($sender)
            ->post(route('message.modern.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Hi',
                'body' => 'Body',
                'action' => 'send',
            ])
            ->assertRedirect(route('message.modern.send'))
            ->assertSessionHas('error');

        $this->assertSame(0, Message::count());
    }

    public function test_modern_store_surfaces_a_validation_error_past_the_image_cap(): void
    {
        // The Modern form sends every selected file (no silent client truncation); the server caps
        // the count, so 4 attachments is a validation error rather than a quiet drop.
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.modern.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Too many',
                'body' => 'Body',
                'action' => 'send',
                'images' => [
                    UploadedFile::fake()->image('a.png', 20, 20),
                    UploadedFile::fake()->image('b.png', 20, 20),
                    UploadedFile::fake()->image('c.png', 20, 20),
                    UploadedFile::fake()->image('d.png', 20, 20),
                ],
            ])
            ->assertSessionHasErrors('images');

        $this->assertSame(0, Message::count());
    }

    public function test_modern_only_serves_the_canonical_compose_as_inertia(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');
        [$viewer, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->get(route('message.compose', ['id' => $recipient->getKey()]))
            ->assertInertia(fn ($page) => $page->component('message/compose'));
    }
}
