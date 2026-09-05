<?php

namespace Tests\Feature\DirectMessage\Modern;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The GET forms redirect into the conversation; this pins the submits, which are surface-agnostic
 * and land back in the mailbox's own boxes.
 */
class DirectMessageComposeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = app(SendDirectMessage::class)($sender, new DirectMessageComposeData($recipient->getKey(), 'X', 'Y'), asDraft: false);

        $this->get(route('message.compose', ['id' => $recipient->getKey()]))->assertRedirect('/login');
        $this->get(route('message.reply', $message))->assertRedirect('/login');
        $this->post(route('message.compose.store'))->assertRedirect('/login');
    }

    public function test_modern_store_sends_and_redirects_to_the_sent_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Hello there',
                'body' => 'Body text',
                'action' => 'send',
            ])
            ->assertRedirect(route('message.send'));

        $this->assertFalse(DirectMessage::firstOrFail()->is_draft);
        Notification::assertSentTo($recipient, DirectMessageReceivedNotification::class);
    }

    public function test_modern_store_saves_a_draft_and_redirects_to_the_draft_box(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'A draft',
                'body' => 'Later',
                'action' => 'draft',
            ])
            ->assertRedirect(route('message.draft'));

        $this->assertTrue(DirectMessage::firstOrFail()->is_draft);
        Notification::assertNothingSent();
    }

    public function test_modern_store_flashes_an_error_when_the_send_is_blocked(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $recipient->forceFill(['is_login_rejected' => true])->save(); // a banned member cannot receive

        $this->actingAs($sender)
            ->post(route('message.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Hi',
                'body' => 'Body',
                'action' => 'send',
            ])
            ->assertRedirect(route('message.send'))
            ->assertSessionHas('error');

        $this->assertSame(0, DirectMessage::count());
    }

    public function test_modern_store_surfaces_a_validation_error_past_the_image_cap(): void
    {
        // The Modern form sends every selected file (no silent client truncation); the server caps
        // the count, so 4 attachments is a validation error rather than a quiet drop.
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.compose.store'), [
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

        $this->assertSame(0, DirectMessage::count());
    }

    /** The submit lands on its box and is forwarded on, so its answer has to survive the extra hop. */
    public function test_a_submits_flash_reaches_the_conversation_list(): void
    {
        Notification::fake();
        [$sender, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($sender)
            ->post(route('message.compose.store'), [
                'to' => $recipient->getKey(),
                'subject' => 'Hello there',
                'body' => 'Body text',
                'action' => 'send',
            ])
            ->assertRedirect(route('message.send'));

        $this->actingAs($sender)->get(route('message.send'))
            ->assertRedirect(route('message.chat.index'))
            ->assertSessionHas('status');

        $this->actingAs($sender)->get(route('message.chat.index'))
            ->assertInertia(fn ($page) => $page->where('flash.status', __('The message was sent successfully.')));
    }

    public function test_modern_only_sends_the_compose_url_into_the_conversation(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        [$viewer, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->get(route('message.compose', ['id' => $recipient->getKey()]))
            ->assertRedirect(route('message.chat.show', ['member' => $recipient->getKey()]));
    }
}
