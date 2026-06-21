<?php

namespace Tests\Feature\Message\Classic;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): Message
    {
        $m = Message::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        MessageRecipient::factory()->create([...['message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    public function test_box_routes_require_authentication(): void
    {
        $this->get('/message/receiveList')->assertRedirect('/login');
    }

    public function test_index_redirects_to_the_inbox(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/message')->assertRedirect(route('message.receive'));
    }

    public function test_inbox_renders_with_the_sender_and_subject(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient, ['subject' => 'A friendly note']);

        $response = $this->actingAs($recipient)->get('/message/receiveList');

        $response->assertOk();
        $response->assertSee('id="page_message_list"', false);              // OpenPNE 3 body id
        $response->assertSee('A friendly note');
        $response->assertSee("/member/{$sender->getKey()}", false);          // From link
        $response->assertSee(route('message.receive.show', ['message' => Message::first()->getKey()]), false);
    }

    public function test_empty_box_shows_the_empty_state(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/message/sendList')
            ->assertOk()
            ->assertSee('There are no messages');
    }

    public function test_received_show_renders_and_marks_read(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient, ['subject' => 'Read me', 'body' => 'Body text here']);

        $response = $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]));

        $response->assertOk();
        $response->assertSee('id="page_message_show"', false);
        $response->assertSee('Read me');
        $response->assertSee('Body text here');
        $this->assertNotNull($message->recipients()->first()->fresh()->read_at);
    }

    public function test_received_show_404s_for_a_non_recipient(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $message = $this->deliver($sender, $recipient);

        $this->actingAs($stranger)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertNotFound();
    }

    public function test_sent_show_404s_for_the_wrong_box(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        // The recipient cannot open a delivered message through the sent box (they are not its sender).
        $this->actingAs($recipient)->get(route('message.send.show', ['message' => $message->getKey()]))
            ->assertNotFound();
    }
}
