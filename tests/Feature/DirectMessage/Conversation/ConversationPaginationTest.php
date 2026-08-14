<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The (created_at, id) keyset. Ordering by id alone would be wrong on upgraded content — rows arrive
 * in transfer order, not chronological order — and created_at alone is not a total order at MySQL's
 * one-second timestamp resolution.
 */
class ConversationPaginationTest extends ConversationTestCase
{
    public function test_the_page_opens_on_the_newest_messages_oldest_first(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', 3)
                ->where('page.messages.0.body', 'message 0')
                ->where('page.messages.2.body', 'message 2')
                ->where('page.hasOlder', false)
                ->where('page.hasNewer', false));
    }

    public function test_the_first_page_is_capped_and_reports_that_more_remain(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $total = ConversationMessages::PER_PAGE + 10;
        $this->conversation($viewer, $other, $total);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', ConversationMessages::PER_PAGE)
                ->where('page.hasOlder', true)
                // The newest message is the last row of the newest page.
                ->where('page.messages.'.(ConversationMessages::PER_PAGE - 1).'.body', 'message '.($total - 1)));
    }

    public function test_a_before_cursor_returns_the_page_that_precedes_it(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 5);

        $cursor = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->json('messages.2.cursor'); // "message 2"

        $older = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?before=".urlencode($cursor))
            ->assertOk()
            ->json();

        $this->assertSame(['message 0', 'message 1'], $this->bodies($older));
        $this->assertFalse($older['hasOlder']);
        // A backwards read never claims rows follow it: what does is already on the client's screen.
        $this->assertFalse($older['hasNewer']);
    }

    public function test_an_after_cursor_returns_only_what_is_newer(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 5);

        $cursor = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->json('messages.2.cursor');

        $newer = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?after=".urlencode($cursor))
            ->assertOk()
            ->json();

        $this->assertSame(['message 3', 'message 4'], $this->bodies($newer));
        $this->assertFalse($newer['hasOlder']);
        $this->assertFalse($newer['hasNewer']);
    }

    public function test_the_newest_cursor_polls_back_nothing_until_something_arrives(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        $cursor = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages")
            ->json('messages.2.cursor');

        $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?after=".urlencode($cursor))
            ->assertJsonCount(0, 'messages');

        $at = Carbon::parse('2026-08-14 10:00:00');
        $this->deliver($other, $viewer, ['body' => 'and one more', 'created_at' => $at, 'updated_at' => $at]);

        $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?after=".urlencode($cursor))
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'and one more');
    }

    /** A forward read that hits its cap is the one page shape that reports rows beyond it. */
    public function test_a_forward_read_reports_what_it_could_not_carry(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, ConversationMessages::PER_PAGE + 5);

        // A position a page behind the newest, so more than one page follows it.
        $oldestLoaded = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json('messages.0.cursor');
        $cursor = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?before=".urlencode($oldestLoaded))
            ->json('messages.0.cursor');

        $forward = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?after=".urlencode($cursor))
            ->json();

        $this->assertCount(ConversationMessages::PER_PAGE, $forward['messages']);
        $this->assertTrue($forward['hasNewer']);
    }

    /**
     * Same-second messages are what the id half of the tuple is for: created_at alone leaves their
     * order to the engine, and a page boundary landing between two of them would drop or repeat one.
     */
    public function test_messages_written_in_the_same_second_keep_a_stable_order(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $at = Carbon::parse('2026-08-14 09:00:00');
        foreach (['first', 'second', 'third'] as $i => $body) {
            $mine = $i % 2 === 0;
            $this->deliver(
                $mine ? $viewer : $other,
                $mine ? $other : $viewer,
                ['body' => $body, 'created_at' => $at, 'updated_at' => $at],
            );
        }

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();
        $this->assertSame(['first', 'second', 'third'], $this->bodies($page));

        // A cursor on the middle row splits the second cleanly in both directions.
        $cursor = urlencode($page['messages'][1]['cursor']);

        $this->assertSame(
            ['first'],
            $this->bodies($this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages?before={$cursor}")->json()),
        );
        $this->assertSame(
            ['third'],
            $this->bodies($this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages?after={$cursor}")->json()),
        );
    }

    /** before → after walks the whole conversation back and forward with no gap and no repeat. */
    public function test_the_pages_either_side_of_a_cursor_are_continuous(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 6);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();
        $cursor = urlencode($page['messages'][3]['cursor']);

        $before = $this->bodies($this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages?before={$cursor}")->json());
        $after = $this->bodies($this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages?after={$cursor}")->json());

        $this->assertSame(['message 0', 'message 1', 'message 2'], $before);
        $this->assertSame(['message 4', 'message 5'], $after);
        $this->assertSame($this->bodies($page), [...$before, 'message 3', ...$after]);
    }

    public function test_a_context_cursor_opens_the_page_the_position_sits_in(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 80);

        // Walk back one page to reach a position the newest page does not hold.
        $oldest = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json('messages.0.cursor');
        $cursor = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?before=".urlencode($oldest))
            ->json('messages.19.cursor'); // "message 19"

        $around = $this->actingAs($viewer)
            ->getJson("/messages/{$other->getKey()}/messages?context=".urlencode($cursor))
            ->assertOk()
            ->json();

        // The context page: the last ten at or before the position, then everything after it.
        $this->assertSame('message 10', $around['messages'][0]['body']);
        $this->assertSame('message 19', $around['messages'][ConversationMessages::CONTEXT - 1]['body']);
        $this->assertSame('message 20', $around['messages'][ConversationMessages::CONTEXT]['body']);
        $this->assertTrue($around['hasOlder']);
        $this->assertTrue($around['hasNewer']);
    }

    public function test_a_malformed_cursor_is_read_as_no_cursor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        foreach (['not-a-cursor', '', '2026-08-14T09:00:00+09:00|abc'] as $bad) {
            $this->actingAs($viewer)
                ->getJson("/messages/{$other->getKey()}/messages?before=".urlencode($bad))
                ->assertOk()
                ->assertJsonCount(3, 'messages');
        }
    }

    /** A query-string array (`?before[]=`) is malformed like any other unparseable cursor, not a 500. */
    public function test_an_array_cursor_is_read_as_no_cursor(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 3);

        foreach (['before', 'after', 'context'] as $param) {
            $this->actingAs($viewer)
                ->getJson("/messages/{$other->getKey()}/messages?{$param}[]=x")
                ->assertOk()
                ->assertJsonCount(3, 'messages');
        }
    }

    /** A page is one read plus the fan-out, whatever its length. */
    public function test_reading_a_page_costs_no_query_per_message(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->conversation($viewer, $other, 20);

        $this->actingAs($viewer);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson("/messages/{$other->getKey()}/messages")->assertOk();

        // Session, the feature gate, the page read and its eager loads — a constant, and far below
        // the 20 rows it just serialized.
        $this->assertLessThan(15, $queries, "reading 20 messages took {$queries} queries");
    }
}
