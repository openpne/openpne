<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The (created_at, id) keyset. Ordering by id alone would be wrong on migrated content — rows arrive
 * in transfer order, not chronological order — and ordering by created_at alone is not a total order
 * at MySQL's one-second timestamp resolution.
 */
class GroupTalkPaginationTest extends TalkTestCase
{
    /** @return list<GroupMessage> $count messages, one per minute, oldest first */
    private function conversation(Group $group, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $at = Carbon::parse('2026-08-13 09:00:00')->addMinutes($i);
            $messages[] = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'body' => "message {$i}",
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $messages;
    }

    public function test_the_page_opens_on_the_newest_messages_oldest_first(): void
    {
        $group = $this->group();
        $this->conversation($group, 3);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', 3)
                ->where('page.messages.0.body', 'message 0')
                ->where('page.messages.2.body', 'message 2')
                ->where('page.hasOlder', false));
    }

    public function test_the_first_page_is_capped_and_reports_that_more_remain(): void
    {
        $group = $this->group();
        $total = GroupTalkMessages::PER_PAGE + 10;
        $this->conversation($group, $total);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages', GroupTalkMessages::PER_PAGE)
                ->where('page.hasOlder', true)
                // The newest message is the last row of the newest page.
                ->where('page.messages.'.(GroupTalkMessages::PER_PAGE - 1).'.body', 'message '.($total - 1)));
    }

    public function test_a_before_cursor_returns_the_page_that_precedes_it(): void
    {
        $group = $this->group();
        $messages = $this->conversation($group, 5);
        $viewer = $this->memberOf($group);

        $cursor = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->json('messages.2.cursor'); // "message 2"

        $older = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?before=".urlencode($cursor))
            ->assertOk()
            ->json();

        $this->assertSame(['message 0', 'message 1'], array_column($older['messages'], 'body'));
        $this->assertFalse($older['hasOlder']);
        $this->assertNotContains($messages[2]->getKey(), array_column($older['messages'], 'id'));
    }

    public function test_an_after_cursor_returns_only_what_is_newer(): void
    {
        $group = $this->group();
        $this->conversation($group, 5);
        $viewer = $this->memberOf($group);

        $cursor = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->json('messages.2.cursor');

        $newer = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?after=".urlencode($cursor))
            ->assertOk()
            ->json('messages');

        $this->assertSame(['message 3', 'message 4'], array_column($newer, 'body'));
    }

    public function test_the_newest_cursor_polls_back_nothing_until_something_arrives(): void
    {
        $group = $this->group();
        $this->conversation($group, 3);
        $viewer = $this->memberOf($group);

        $cursor = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->json('messages.2.cursor');

        $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?after=".urlencode($cursor))
            ->assertJsonCount(0, 'messages');

        GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'body' => 'and one more',
            'created_at' => Carbon::parse('2026-08-13 10:00:00'),
            'updated_at' => Carbon::parse('2026-08-13 10:00:00'),
        ]);

        $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?after=".urlencode($cursor))
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'and one more');
    }

    /**
     * Same-second messages are what the id half of the tuple is for: created_at alone leaves their
     * order to the engine, and a page boundary landing between two of them would drop or repeat one.
     */
    public function test_messages_written_in_the_same_second_keep_a_stable_order(): void
    {
        $group = $this->group();
        $at = Carbon::parse('2026-08-13 09:00:00');
        foreach (['first', 'second', 'third'] as $body) {
            GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'body' => $body,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        $viewer = $this->memberOf($group);

        $page = $this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages")->json();
        $this->assertSame(['first', 'second', 'third'], array_column($page['messages'], 'body'));

        // A cursor on the middle row splits the second cleanly in both directions.
        $cursor = urlencode($page['messages'][1]['cursor']);

        $this->assertSame(
            ['first'],
            array_column($this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages?before={$cursor}")->json('messages'), 'body'),
        );
        $this->assertSame(
            ['third'],
            array_column($this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages?after={$cursor}")->json('messages'), 'body'),
        );
    }

    /** A page is one read plus the author fan-out, whatever its length. */
    public function test_reading_a_page_costs_no_query_per_message(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->reactedConversation($group, $viewer);

        $this->actingAs($viewer);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk();

        // Session, access gate, permissions, the page read, its eager loads and the one grouped
        // count behind the chips — a constant, and far below the 20 rows it just serialized.
        $this->assertLessThan(15, $queries, "reading 20 messages took {$queries} queries");
    }

    /**
     * And no row per *reactor*: a chip row is a handful of numbers, but the rows behind it grow with
     * the room, so the page counts them in SQL rather than hydrating them. A page that eager-loaded
     * the relation would cost the same query count and read every reaction in the conversation.
     */
    public function test_reading_a_page_never_reads_a_reaction_row(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->reactedConversation($group, $viewer);

        $this->actingAs($viewer);
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (preg_match('/from\s+[`"]?reactions[`"]?/i', $query->sql) === 1) {
                $reads[] = $query->sql;
            }
        });

        $this->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk();

        $this->assertNotSame([], $reads, 'the page read no reactions at all');
        foreach ($reads as $sql) {
            $this->assertMatchesRegularExpression('/group by/i', $sql, "a page hydrated reaction rows: {$sql}");
        }
    }

    /** Twenty messages, each carrying reactions from two different members. */
    private function reactedConversation(Group $group, Member $viewer): void
    {
        foreach (range(1, 20) as $ignored) {
            $message = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $this->memberOf($group)->getKey(),
            ]);
            $message->reactions()->create(['member_id' => $viewer->getKey(), 'emoji' => "\u{1F44D}"]);
            $message->reactions()->create(['member_id' => $this->memberOf($group)->getKey(), 'emoji' => "\u{1F389}"]);
        }
    }

    public function test_a_malformed_cursor_is_read_as_no_cursor(): void
    {
        $group = $this->group();
        $this->conversation($group, 3);

        $this->actingAs($this->memberOf($group))
            ->getJson("/groups/{$group->getKey()}/talk/messages?before=not-a-cursor")
            ->assertOk()
            ->assertJsonCount(3, 'messages');
    }

    public function test_one_group_never_reads_another_s_conversation(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'body' => 'ours']);
        GroupMessage::factory()->create(['group_id' => $elsewhere->getKey(), 'body' => 'theirs']);

        $this->actingAs(Member::factory()->create())
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'ours');
    }
}
