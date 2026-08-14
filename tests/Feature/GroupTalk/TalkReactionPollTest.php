<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Testing\TestResponse;

/**
 * How an open tab hears about a reaction. The message poll reads forward from a (created_at, id)
 * position, which a reaction never moves, so the group's reaction version is the second watermark
 * the same request carries.
 */
class TalkReactionPollTest extends TalkReactionTestCase
{
    public function test_each_change_of_state_moves_the_version_forward(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();
        $first = $this->seq($group);

        $this->react($member, $group, $message, $this->emoji(1))->assertOk();
        $second = $this->seq($group);

        $this->unreact($member, $group, $message)->assertOk();
        $third = $this->seq($group);

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan($first, $second);
        $this->assertGreaterThan($second, $third);
        // The message carries the last version it was issued.
        $this->assertSame($third, (int) $message->fresh()->reactions_version);
    }

    public function test_a_poll_returns_the_messages_whose_reactions_changed(): void
    {
        $group = $this->group();
        $untouched = $this->message($group);
        $touched = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $touched)->assertOk();

        $body = $this->poll($member, $group, 0)->assertOk()->json();

        $this->assertSame([$touched->getKey()], array_column($body['touched'], 'id'));
        $this->assertSame([['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]], $body['touched'][0]['reactions']);
        $this->assertSame($this->seq($group), $body['reactionsVersion']);
        $this->assertNotContains($untouched->getKey(), array_column($body['touched'], 'id'));

        // Nothing has changed since, so the same watermark comes back empty.
        $this->poll($member, $group, $body['reactionsVersion'])->assertJsonCount(0, 'touched');
    }

    /**
     * A capped page reports the last row it returned rather than the pre-query snapshot: moving the
     * watermark to the snapshot would step over everything the cap left behind, and nothing would ask
     * for it again.
     */
    public function test_more_touched_messages_than_a_page_are_collected_across_polls(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $total = GroupTalkMessages::PER_PAGE + 1;

        $ids = [];
        foreach (range(1, $total) as $ignored) {
            $message = $this->message($group);
            $ids[] = $message->getKey();
            $this->react($member, $group, $message)->assertOk();
        }

        $first = $this->poll($member, $group, 0)->assertOk()->json();
        $this->assertCount(GroupTalkMessages::PER_PAGE, $first['touched']);
        $this->assertLessThan($this->seq($group), $first['reactionsVersion']);

        $second = $this->poll($member, $group, $first['reactionsVersion'])->assertOk()->json();
        $this->assertCount(1, $second['touched']);
        $this->assertSame($this->seq($group), $second['reactionsVersion']);

        $this->assertSame($ids, [...array_column($first['touched'], 'id'), ...array_column($second['touched'], 'id')]);
    }

    /** A row's version is replaced, not appended to, so a busy message arrives once at its latest state. */
    public function test_repeated_changes_to_one_message_arrive_as_one_row(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();
        $this->react($member, $group, $message, $this->emoji(1))->assertOk();
        $this->unreact($member, $group, $message)->assertOk();

        $body = $this->poll($member, $group, 0)->assertOk()->json();

        $this->assertCount(1, $body['touched']);
        $this->assertSame([['emoji' => $this->emoji(1), 'count' => 1, 'mine' => true]], $body['touched'][0]['reactions']);
    }

    /** A client that does not speak the protocol — a tab from before this shipped, or the DM poll. */
    public function test_a_poll_without_a_watermark_answers_the_shape_it_always_did(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);
        $this->react($member, $group, $message)->assertOk();

        $this->actingAs($member)
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->assertOk()
            ->assertJsonMissingPath('touched')
            ->assertJsonMissingPath('reactionsVersion');
    }

    /** An unparseable watermark is no watermark, exactly as an unparseable cursor is no cursor. */
    public function test_a_malformed_watermark_is_read_as_none(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)
            ->getJson("/groups/{$group->getKey()}/talk/messages?reactionsAfter=soon")
            ->assertOk()
            ->assertJsonMissingPath('touched');
    }

    /**
     * The page ships the watermark it was rendered at, and a reaction that lands after it is still
     * waiting on the next poll — which is why the version is read before the page, not after.
     */
    public function test_the_page_ships_a_watermark_the_next_poll_can_continue_from(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);
        $this->react($member, $group, $message)->assertOk();

        $rendered = $this->actingAs($member)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertOk()
            ->viewData('page')['props']['reactionsVersion'];

        $this->assertSame($this->seq($group), $rendered);
        $this->poll($member, $group, $rendered)->assertJsonCount(0, 'touched');

        $this->react($this->memberOf($group), $group, $message)->assertOk();

        $this->poll($member, $group, $rendered)
            ->assertJsonCount(1, 'touched')
            ->assertJsonPath('touched.0.reactions.0.count', 2);
    }

    /** What the picker draws, so the client never holds a vocabulary of its own. */
    public function test_the_page_ships_the_vocabulary(): void
    {
        $group = $this->group();

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('reactionVocabulary.0', $this->emoji(0)));
    }

    /**
     * Reactions are not messages: they create no row the unread predicate can see, and no unread read
     * looks at the version. A badge that counted them would make a room look unread for a thumbs-up.
     */
    public function test_reacting_moves_no_unread_count(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $reader = $this->memberOf($group);
        $reactor = $this->memberOf($group);

        $before = $this->unreadState($reader, $group);

        $this->react($reactor, $group, $message)->assertOk();
        $this->unreact($reactor, $group, $message)->assertOk();

        $this->assertSame($before, $this->unreadState($reader, $group));
    }

    /** @return array{counts: array<string, mixed>, snapshot: mixed} */
    private function unreadState(Member $reader, Group $group): array
    {
        return [
            'counts' => $this->actingAs($reader)->getJson('/unread-counts')->assertOk()->json('unread'),
            'snapshot' => $this->actingAs($reader)
                ->get("/groups/{$group->getKey()}/talk")
                ->viewData('page')['props']['talkUnreadSnapshot'],
        ];
    }

    private function poll(Member $member, Group $group, int $after): TestResponse
    {
        return $this->actingAs($member)->getJson("/groups/{$group->getKey()}/talk/messages?reactionsAfter={$after}");
    }
}
