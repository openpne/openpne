<?php

namespace Tests\Feature\DirectMessage\Modern;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectMessageRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function deliver(?Member $sender, ?Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender?->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient?->getKey()], ...$receipt]);

        return $m;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $message = $this->deliver(...Member::factory()->count(2)->create()->all());

        $this->get('/messages')->assertRedirect('/login');
        $this->get('/message')->assertRedirect('/login');
        $this->get('/message/receiveList')->assertRedirect('/login');
        $this->get('/message/sendList')->assertRedirect('/login');
        $this->get('/message/draftList')->assertRedirect('/login');
        $this->get('/message/dustList')->assertRedirect('/login');
        $this->get(route('message.receive.show', $message))->assertRedirect('/login');
    }

    /** The four boxes and both index aliases: the conversation list answers for all of them. */
    public function test_every_box_url_lands_on_the_conversation_list(): void
    {
        $member = Member::factory()->create();
        $list = route('message.chat.index');

        foreach (['message.index', 'message.index_compat', 'message.receive', 'message.send', 'message.draft', 'message.trash'] as $name) {
            $this->actingAs($member)->get(route($name))->assertRedirect($list);
        }
    }

    /** The boxes stay whole on Classic: the redirect is the Modern arm's alone. */
    public function test_classic_still_renders_the_boxes(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient, ['subject' => 'A friendly note']);

        $this->actingAs($recipient)->get(route('message.receive'))
            ->assertOk()->assertSee('id="page_message_list"', false)->assertSee('A friendly note');
        $this->actingAs($recipient)->get(route('message.receive.show', $message))
            ->assertOk()->assertSee('id="page_message_show"', false);
        $this->actingAs($sender)->get(route('message.compose', ['id' => $recipient->getKey()]))
            ->assertOk()->assertSee('id="page_message_sendToFriend"', false);
    }

    /**
     * The counterpart is seen from the viewer's side, so the same row sends the two of them to each
     * other rather than both to the sender.
     */
    public function test_a_message_url_lands_on_its_conversation_from_each_sides_view(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);
        $id = $message->getKey();

        $this->actingAs($recipient)->get(route('message.receive.show', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $sender->getKey()]).'?m='.$id);
        $this->actingAs($sender)->get(route('message.send.show', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $recipient->getKey()]).'?m='.$id);
        $this->actingAs($recipient)->get(route('message.trash.show', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $sender->getKey()]).'?m='.$id);
    }

    /** A departed counterpart leaves no id to key a conversation by: all of them share one address. */
    public function test_a_withdrawn_counterpart_lands_on_the_withdrawn_bucket(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);
        $sender->delete(); // nullOnDelete leaves the message with a null sender

        $this->actingAs($recipient)->get(route('message.receive.show', $message))
            ->assertRedirect(route('message.chat.withdrawn').'?m='.$message->getKey());
    }

    /** Reading marks read; being sent past a message must not, or the divider is gone on arrival. */
    public function test_the_redirect_does_not_mark_the_message_read(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $this->actingAs($recipient)->get(route('message.receive.show', $message))->assertRedirect();

        $this->assertNull($message->recipients()->first()->fresh()->read_at);
    }

    /**
     * An upgraded multi-recipient send sits in several conversations and a URL can land in one:
     * the first delivery written — the lowest receipt id — names it, not the relation's load order.
     */
    public function test_a_senders_multi_recipient_url_lands_deterministically(): void
    {
        [$sender, $second, $third] = Member::factory()->count(3)->create();
        $message = $this->deliver($sender, $second);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $third->getKey()]);

        $this->actingAs($sender)->get(route('message.send.show', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $second->getKey()]).'?m='.$message->getKey());
    }

    /** The rule holds when the first delivery's member has withdrawn: the bucket is the landing. */
    public function test_the_first_delivery_names_the_landing_even_when_it_is_withdrawn(): void
    {
        [$sender, $active] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, null);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $active->getKey()]);

        $this->actingAs($sender)->get(route('message.send.show', $message))
            ->assertRedirect(route('message.chat.withdrawn').'?m='.$message->getKey());
    }

    /** Replying is writing in the conversation, so it opens it with no anchor. */
    public function test_reply_lands_on_the_conversation(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient);

        $this->actingAs($recipient)->get(route('message.reply', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $sender->getKey()]));
    }

    /** Modern has no trash screen; the OpenPNE 3 purge confirm lands in the conversation. */
    public function test_the_purge_confirm_lands_on_the_conversation(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient, [], ['recipient_deleted_at' => now()]);

        $this->actingAs($recipient)->get(route('message.trash.purge.confirm', $message))
            ->assertRedirect(route('message.chat.show', ['member' => $sender->getKey()]));
    }

    /** A compose URL is a way into a conversation; without one to name, it is the list. */
    public function test_compose_lands_on_the_conversation_or_the_list(): void
    {
        [$viewer, $recipient] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)->get(route('message.compose', ['id' => $recipient->getKey()]))
            ->assertRedirect(route('message.chat.show', ['member' => $recipient->getKey()]));
        $this->actingAs($viewer)->get(route('message.compose', ['id' => $viewer->getKey()]))
            ->assertRedirect(route('message.chat.index'));
        $this->actingAs($viewer)->get(route('message.compose', ['id' => 999999]))
            ->assertRedirect(route('message.chat.index'));
        $this->actingAs($viewer)->get(route('message.compose'))
            ->assertRedirect(route('message.chat.index'));
    }

    public function test_a_stranger_gets_no_redirect_naming_the_parties(): void
    {
        [$sender, $recipient, $stranger] = Member::factory()->count(3)->create();
        $message = $this->deliver($sender, $recipient);

        foreach (['message.receive.show', 'message.send.show', 'message.trash.show', 'message.reply', 'message.trash.purge.confirm'] as $name) {
            $this->actingAs($stranger)->get(route($name, ['message' => $message->getKey()]))->assertNotFound();
        }
    }

    public function test_a_missing_message_is_still_a_404(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get(route('message.receive.show', ['message' => 999999]))->assertNotFound();
        $this->actingAs($member)->get(route('message.reply', ['message' => 999999]))->assertNotFound();
    }

    /** A draft has no receipt, so it is in no conversation — only in the form still writing it. */
    public function test_a_draft_named_as_a_message_is_a_404(): void
    {
        [$author, $recipient] = Member::factory()->count(2)->create();
        $draft = DirectMessage::factory()->draft()->create([
            'sender_id' => $author->getKey(),
            'draft_recipient_id' => $recipient->getKey(),
        ]);

        $this->actingAs($author)->get(route('message.receive.show', $draft))->assertNotFound();
    }

    /** The draft form is the one mailbox screen Modern still renders: no conversation holds a draft. */
    public function test_the_draft_form_still_renders(): void
    {
        [$author, $recipient] = Member::factory()->count(2)->create();
        $draft = DirectMessage::factory()->draft()->create([
            'sender_id' => $author->getKey(),
            'draft_recipient_id' => $recipient->getKey(),
            'subject' => 'Unsent',
        ]);

        $this->actingAs($author)
            ->get(route('message.draft.edit', $draft))
            ->assertInertia(fn ($page) => $page->component('message/edit')->where('draft.subject', 'Unsent'));
    }

    public function test_modern_only_serves_the_conversation_list_as_inertia(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get(route('message.chat.index'))
            ->assertInertia(fn ($page) => $page->component('message/conversations/index'));
    }
}
