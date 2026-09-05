<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Models\DirectMessage;
use App\Models\Member;
use PHPUnit\Framework\Attributes\DataProvider;

class ConversationAnchorTest extends ConversationTestCase
{
    public function test_the_page_opens_on_the_slice_the_named_message_sits_in(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $messages = $this->conversation($viewer, $other, 80);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m={$messages[19]->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', (int) $messages[19]->getKey())
                // The context page: the last ten at or before the message, then what follows it.
                ->where('page.messages.0.body', 'message 10')
                ->where('page.messages.'.(ConversationMessages::CONTEXT - 1).'.body', 'message 19')
                ->where('page.messages.'.ConversationMessages::CONTEXT.'.body', 'message 20')
                ->where('page.hasOlder', true)
                ->where('page.hasNewer', true));
    }

    public function test_an_ordinary_visit_carries_no_anchor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.2.body', 'message 2'));
    }

    /**
     * Unlike a pagination cursor, an id from elsewhere is no position to borrow: it would open this
     * conversation around another one's instant.
     */
    public function test_a_message_from_another_conversation_is_no_anchor(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $this->conversation($viewer, $other, 60);
        $elsewhere = $this->deliver($viewer, $third, ['body' => 'theirs']);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m={$elsewhere->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.'.(ConversationMessages::PER_PAGE - 1).'.body', 'message 59'));
    }

    public function test_a_draft_is_no_anchor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 60);
        $draft = DirectMessage::factory()->draft()->create([
            'sender_id' => $viewer->getKey(),
            'draft_recipient_id' => $other->getKey(),
        ]);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m={$draft->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.'.(ConversationMessages::PER_PAGE - 1).'.body', 'message 59'));
    }

    /** A row the reader has trashed is not in their conversation, so it is not a place in it either. */
    public function test_a_trashed_message_is_no_anchor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $messages = $this->conversation($viewer, $other, 60);
        // An even index is one the viewer sent, so the sender-side column is their own copy.
        $messages[20]->forceFill(['sender_deleted_at' => now()])->save();

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m={$messages[20]->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.'.(ConversationMessages::PER_PAGE - 1).'.body', 'message 59'));
    }

    /** @return array<string, array{0: string}> */
    public static function unusable(): array
    {
        return [
            'not a number' => ['not-a-number'],
            'empty' => [''],
            'negative' => ['-4'],
            'no such message' => ['999999'],
        ];
    }

    #[DataProvider('unusable')]
    public function test_an_id_that_names_nothing_is_no_anchor(string $id): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m=".urlencode($id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.2.body', 'message 2'));
    }
}
