<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;

class ConversationUnreadTest extends ConversationTestCase
{
    /** @return array<string, mixed> the rendered page's props */
    private function props(Member $viewer, ?Member $counterpart): array
    {
        $path = $counterpart === null ? '/messages/withdrawn' : "/messages/{$counterpart->getKey()}";

        return $this->actingAs($viewer)->get($path)->assertOk()->viewData('page')['props'];
    }

    /** One received message at a fixed minute, so the tuple order is the writing order. */
    private function received(Member $viewer, ?Member $counterpart, string $body, int $minute, array $receipt = []): DirectMessage
    {
        $at = Carbon::parse('2026-08-14 09:00:00')->addMinutes($minute);

        return $this->deliver($counterpart, $viewer, ['body' => $body, 'created_at' => $at, 'updated_at' => $at], $receipt);
    }

    public function test_a_conversation_with_nothing_waiting_reports_no_boundary(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->received($viewer, $other, 'read already', 0, ['read_at' => now()]);
        $this->deliver($viewer, $other, ['body' => 'mine']);

        $this->assertNull($this->props($viewer, $other)['unreadSnapshot']);
    }

    public function test_the_boundary_is_the_oldest_message_still_waiting(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->received($viewer, $other, 'read', 0, ['read_at' => now()]);
        $first = $this->received($viewer, $other, 'waiting', 1);
        $this->received($viewer, $other, 'waiting too', 2);

        $snapshot = $this->props($viewer, $other)['unreadSnapshot'];

        $this->assertSame(2, $snapshot['count']);
        $this->assertSame((int) $first->getKey(), $snapshot['firstUnread']['id']);
    }

    /** The mailbox opens messages one at a time, so the fixture's read state has a hole in it. */
    public function test_a_newer_message_read_from_the_mailbox_leaves_the_boundary_behind_it(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $oldest = $this->received($viewer, $other, 'skipped over', 0);
        $this->received($viewer, $other, 'opened in the mailbox', 1, ['read_at' => now()]);
        $this->received($viewer, $other, 'still waiting', 2);

        $snapshot = $this->props($viewer, $other)['unreadSnapshot'];

        $this->assertSame(2, $snapshot['count']);
        $this->assertSame((int) $oldest->getKey(), $snapshot['firstUnread']['id']);
    }

    /** Writing is not something to be read: an unopened message of the viewer's own is the counterpart's business. */
    public function test_the_viewers_own_messages_are_never_unread(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'mine, unopened by them']);

        $this->assertNull($this->props($viewer, $other)['unreadSnapshot']);
    }

    /** The count is over what the screen draws — a row the conversation cannot see is not waiting on it. */
    public function test_nothing_the_conversation_cannot_see_is_counted(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $visible = $this->received($viewer, $other, 'waiting', 5);
        $this->received($viewer, $other, 'trashed by me', 0, ['recipient_deleted_at' => now()]);
        $this->received($viewer, $other, 'purged by me', 1, ['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);
        $this->deliver($other, $viewer, ['body' => 'a draft with a stray receipt', 'is_draft' => true]);
        $this->deliver($third, $viewer, ['body' => 'another conversation']);

        $snapshot = $this->props($viewer, $other)['unreadSnapshot'];

        $this->assertSame(1, $snapshot['count']);
        $this->assertSame((int) $visible->getKey(), $snapshot['firstUnread']['id']);
        $this->assertSame(['waiting'], $this->bodies($this->props($viewer, $other)['page']));
    }

    /**
     * The client finds the boundary row by comparing the two directly, so they have to come out of
     * the same conversion — a differently spelled instant would put the divider in the wrong place,
     * or nowhere.
     */
    public function test_the_boundary_instant_is_spelled_as_a_message_timestamp_is(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->received($viewer, $other, 'read', 0, ['read_at' => now()]);
        $this->received($viewer, $other, 'waiting', 1);

        $props = $this->props($viewer, $other);

        $this->assertSame($props['page']['messages'][1]['createdAt'], $props['unreadSnapshot']['firstUnread']['at']);
        $this->assertSame($props['page']['messages'][1]['id'], $props['unreadSnapshot']['firstUnread']['id']);
    }

    /** The two shapes of one position: the tuple the divider compares, and the cursor the jump uses. */
    public function test_the_boundary_travels_as_a_cursor_the_client_never_assembles(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 60);
        $snapshot = $this->props($viewer, $other)['unreadSnapshot'];

        // The boundary is the oldest received message, and the newest page does not reach it: this is
        // the state the banner offers to fix.
        $this->assertNotContains('message 1', $this->bodies($this->props($viewer, $other)['page']));

        $page = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?context=".urlencode($snapshot['cursor']))
            ->assertOk()
            ->json();

        // around() carries the boundary row at the end of the read side, so the reader lands with
        // what came before it above the line.
        $this->assertSame('message 1', $page['messages'][1]['body']);
        $this->assertSame('message 0', $page['messages'][0]['body']);
    }

    public function test_an_anchor_link_does_not_move_the_boundary(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $messages = $this->conversation($viewer, $other, 6);
        $first = $this->props($viewer, $other)['unreadSnapshot'];

        $anchored = $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}?m={$messages[5]->getKey()}")
            ->assertOk()
            ->viewData('page')['props']['unreadSnapshot'];

        $this->assertSame($first, $anchored);
    }

    public function test_the_withdrawn_bucket_carries_its_own_boundary(): void
    {
        $viewer = Member::factory()->create();
        $this->received($viewer, null, 'read', 0, ['read_at' => now()]);
        $waiting = $this->received($viewer, null, 'waiting', 1);

        $snapshot = $this->props($viewer, null)['unreadSnapshot'];

        $this->assertSame(1, $snapshot['count']);
        $this->assertSame((int) $waiting->getKey(), $snapshot['firstUnread']['id']);
    }

    /**
     * The snapshot is the render-time position: a page already sent keeps naming the line the reader
     * opened on, and only the next render answers from the receipts as they now stand.
     */
    public function test_reading_moves_the_next_boundary_and_not_the_one_already_rendered(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $waiting = $this->received($viewer, $other, 'waiting', 0);

        $opened = $this->actingAs($viewer)->get("/messages/{$other->getKey()}")->assertOk();
        $opened->assertInertia(fn ($page) => $page->where('unreadSnapshot.count', 1));

        $this->actingAs($viewer)
            ->postJson("/messages/{$other->getKey()}/read", ['messageId' => $waiting->getKey()])
            ->assertNoContent();

        $opened->assertInertia(fn ($page) => $page->where('unreadSnapshot.count', 1));
        $this->assertNull($this->props($viewer, $other)['unreadSnapshot']);
    }
}
