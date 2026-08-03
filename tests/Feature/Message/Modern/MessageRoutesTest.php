<?php

namespace Tests\Feature\Message\Modern;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): Message
    {
        $m = Message::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        MessageRecipient::factory()->create([...['message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $message = $this->deliver(...Member::factory()->count(2)->create()->all());

        $this->get('/message')->assertRedirect('/login');
        $this->get('/message/receiveList')->assertRedirect('/login');
        $this->get('/message/sendList')->assertRedirect('/login');
        $this->get('/message/draftList')->assertRedirect('/login');
        $this->get('/message/dustList')->assertRedirect('/login');
        $this->get(route('message.receive.show', $message))->assertRedirect('/login');
    }

    public function test_modern_index_redirects_to_the_inbox(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/message')->assertRedirect(route('message.receive'));
    }

    public function test_modern_inbox_lists_the_sender_subject_and_unread_state(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'A friendly note']);

        $this->actingAs($recipient)
            ->get(route('message.receive'))
            ->assertInertia(fn ($page) => $page
                ->component('message/index')
                ->where('box', 'receive')
                ->has('messages.data', 1)
                ->where('messages.data.0.subject', 'A friendly note')
                ->where('messages.data.0.counterparty.id', $sender->getKey())
                ->where('messages.data.0.unread', true)
            );
    }

    public function test_modern_inbox_shows_a_null_counterparty_for_a_withdrawn_sender(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);
        $sender->delete(); // nullOnDelete leaves the message with a null sender

        $this->actingAs($recipient)
            ->get(route('message.receive'))
            ->assertInertia(fn ($page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.counterparty', null)
            );
    }

    public function test_modern_sent_box_lists_authored_messages(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Sent one']);

        $this->actingAs($sender)
            ->get(route('message.send'))
            ->assertInertia(fn ($page) => $page
                ->component('message/index')
                ->where('box', 'sent')
                ->where('messages.data.0.subject', 'Sent one')
                ->where('messages.data.0.counterparty.id', $recipient->getKey())
                ->where('messages.data.0.unread', false)
            );
    }

    public function test_modern_draft_box_lists_drafts(): void
    {
        [$author, $recipient] = Member::factory()->count(2)->create();
        Message::factory()->draft()->create([
            'sender_id' => $author->getKey(),
            'draft_recipient_id' => $recipient->getKey(),
            'subject' => 'Unsent',
        ]);

        $this->actingAs($author)
            ->get(route('message.draft'))
            ->assertInertia(fn ($page) => $page
                ->component('message/index')
                ->where('box', 'draft')
                ->where('messages.data.0.subject', 'Unsent')
                ->where('messages.data.0.counterparty.id', $recipient->getKey())
            );
    }

    public function test_modern_trash_box_lists_trashed_messages(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'Tossed'], ['recipient_deleted_at' => now()]);

        $this->actingAs($recipient)
            ->get(route('message.trash'))
            ->assertInertia(fn ($page) => $page
                ->component('message/index')
                ->where('box', 'trash')
                ->where('messages.data.0.subject', 'Tossed')
            );
    }

    public function test_modern_received_show_renders_and_marks_read(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient, ['subject' => 'Read me', 'body' => 'Body text here']);

        $this->actingAs($recipient)
            ->get(route('message.receive.show', $message))
            ->assertInertia(fn ($page) => $page
                ->component('message/show')
                ->where('message.id', $message->getKey())
                ->where('message.subject', 'Read me')
                ->where('message.body', 'Body text here')
                ->where('message.viewerIsSender', false)
                ->where('message.box', 'receive')
                ->where('message.counterparties.0.id', $sender->getKey())
            );

        $this->assertNotNull($message->recipients()->first()->fresh()->read_at);
    }

    public function test_modern_sent_show_lists_the_recipient_as_counterparty(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $this->actingAs($sender)
            ->get(route('message.send.show', $message))
            ->assertInertia(fn ($page) => $page
                ->component('message/show')
                ->where('message.viewerIsSender', true)
                ->where('message.counterparties.0.id', $recipient->getKey())
            );
    }

    public function test_modern_sent_show_lists_every_recipient_as_a_counterparty(): void
    {
        // More than one counterparty is what keeps the chrome from naming a single member as the
        // page's scope, so the array shape is the server-side half of that rule.
        [$sender, $first, $second] = Member::factory()->count(3)->create();
        $message = $this->deliver($sender, $first);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $second->getKey()]);

        $this->actingAs($sender)
            ->get(route('message.send.show', $message))
            ->assertInertia(fn ($page) => $page
                ->component('message/show')
                ->has('message.counterparties', 2)
                ->where('message.counterparties.0.id', $first->getKey())
                ->where('message.counterparties.1.id', $second->getKey())
            );
    }

    public function test_modern_show_serializes_the_prev_next_pager(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $first = $this->deliver($sender, $recipient);
        $middle = $this->deliver($sender, $recipient);
        $last = $this->deliver($sender, $recipient);

        $this->actingAs($recipient)
            ->get(route('message.receive.show', $middle))
            ->assertInertia(fn ($page) => $page
                ->where('message.previousId', $first->getKey())
                ->where('message.nextId', $last->getKey())
            );
    }

    public function test_modern_received_show_404s_for_a_non_recipient(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $message = $this->deliver($sender, $recipient);

        $this->actingAs($stranger)->get(route('message.receive.show', $message))->assertNotFound();
    }

    public function test_modern_sent_show_404s_for_the_wrong_box(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        // The recipient is not the sender, so the message is not in their sent box.
        $this->actingAs($recipient)->get(route('message.send.show', $message))->assertNotFound();
    }

    public function test_modern_only_serves_the_canonical_message_boxes_as_inertia(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get(route('message.receive'))
            ->assertInertia(fn ($page) => $page->component('message/index'));
    }
}
