<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Carbon;

/**
 * The unread boundary as a place the reader can go: the snapshot the talk page renders with, and the
 * page that opens on it.
 */
class TalkUnreadBoundaryTest extends TalkTestCase
{
    /** @return list<GroupMessage> $count messages by someone else, one per minute, oldest first */
    private function conversation(Group $group, int $count): array
    {
        $author = $this->memberOf($group);
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $at = Carbon::parse('2026-08-14 09:00:00')->addMinutes($i);
            $messages[] = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $author->getKey(),
                'body' => "message {$i}",
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $messages;
    }

    private function markRead(Member $viewer, Group $group, GroupMessage $message): void
    {
        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $message->getKey()])
            ->assertNoContent();
    }

    public function test_the_page_carries_the_boundary_the_member_opened_on(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 5);
        $this->markRead($viewer, $group, $messages[2]);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->where('talkUnreadSnapshot.count', 2)
                ->where('talkUnreadSnapshot.readThrough.id', (int) $messages[2]->getKey()));
    }

    /**
     * The client finds the first row past the boundary by comparing the two directly, so they have to
     * come out of the same conversion — a differently spelled instant would put the divider in the
     * wrong place, or nowhere.
     */
    public function test_the_boundary_instant_is_spelled_as_a_message_timestamp_is(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 3);
        $this->markRead($viewer, $group, $messages[1]);

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        $this->assertSame($props['page']['messages'][1]['createdAt'], $props['talkUnreadSnapshot']['readThrough']['at']);
    }

    /** No membership row, no cursor: a boundary of zero would claim they had read the group. */
    public function test_a_non_member_reader_gets_no_boundary(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $this->conversation($group, 3);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('talkUnreadSnapshot', null));
    }

    /** The screen goes with its unit, and the boundary with the screen. */
    public function test_the_page_is_gone_while_the_unit_is_switched_off(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 2);
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->assertNotFound();
    }

    /**
     * The snapshot is a position, the badge is a live count, and reading moves one without the other.
     * A page rendered before the read still names the line the reader opened on; the next render is
     * where the boundary has moved.
     */
    public function test_reading_moves_the_badge_and_leaves_the_rendered_boundary_behind(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 3);

        $opened = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk");
        $opened->assertInertia(fn ($page) => $page->where('talkUnreadSnapshot.count', 3));

        $this->markRead($viewer, $group, $messages[2]);

        // The aggregate memoizes per request, and one test method makes several through the same
        // container; without this the badge would be answered from the render above.
        $this->freshRequestState();
        $this->actingAs($viewer)->getJson('/unread-counts')->assertJsonPath('groupTalks', 0);
        // The response already sent is unchanged by any of it — the divider does not move under the
        // reader — while a fresh render answers from the cursor as it now stands.
        $opened->assertInertia(fn ($page) => $page->where('talkUnreadSnapshot.count', 3));
        $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('talkUnreadSnapshot.count', 0));
    }

    /** The two shapes of one position: the tuple the divider compares, and the cursor the jump uses. */
    public function test_the_boundary_travels_as_a_cursor_the_client_never_assembles(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 5);
        $this->markRead($viewer, $group, $messages[2]);

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];
        $snapshot = $props['talkUnreadSnapshot'];

        $this->assertSame($props['page']['messages'][2]['cursor'], $snapshot['cursor']);
        // And it is accepted back: an encoding the client only ever echoes has to round-trip.
        $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode($snapshot['cursor']))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'message 0');
    }

    public function test_a_context_page_opens_on_its_position_with_history_above_it(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 80);
        $this->markRead($viewer, $group, $messages[19]);

        $cursor = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")
            ->viewData('page')['props']['talkUnreadSnapshot']['cursor'];

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode($cursor))
            ->assertOk()
            ->json();

        $bodies = array_column($page['messages'], 'body');
        // The last ten at or before the position, then everything after it up to the cap.
        $this->assertSame('message 10', $bodies[0]);
        $this->assertSame('message 19', $bodies[GroupTalkMessages::CONTEXT - 1]);
        $this->assertSame('message 20', $bodies[GroupTalkMessages::CONTEXT]);
        $this->assertCount(GroupTalkMessages::CONTEXT + GroupTalkMessages::PER_PAGE, $bodies);
        $this->assertTrue($page['hasOlder']);
        $this->assertTrue($page['hasNewer'], 'ten messages remain past the cap');
    }

    public function test_a_context_page_reports_no_newer_once_the_rest_fits_in_one_page(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 15);

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode((string) GroupTalkCursor::of($messages[4])))
            ->assertOk()
            ->json();

        $this->assertSame('message 0', $page['messages'][0]['body'], 'the whole history fits above the position');
        $this->assertFalse($page['hasOlder']);
        $this->assertFalse($page['hasNewer']);
        $this->assertCount(15, $page['messages']);
    }

    /**
     * The regression this whole shape exists for. Mark-read fires seconds after the page opens and
     * moves the stored cursor to the foot of the conversation; a jump that asked the server to
     * resolve "where have I read to" would then answer with the end of the group and land the reader
     * nowhere. The snapshot is a position taken at render time, so it still opens on the boundary.
     */
    public function test_the_jump_still_lands_on_the_boundary_after_mark_read_has_moved_the_cursor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 80);
        $this->markRead($viewer, $group, $messages[19]);

        $cursor = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")
            ->viewData('page')['props']['talkUnreadSnapshot']['cursor'];

        // What opening the page does: the reader lands at the foot and everything is marked read.
        $this->markRead($viewer, $group, $messages[79]);

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode($cursor))
            ->assertOk()
            ->json();

        $this->assertSame('message 19', $page['messages'][GroupTalkMessages::CONTEXT - 1]['body']);
        $this->assertSame('message 20', $page['messages'][GroupTalkMessages::CONTEXT]['body']);
    }

    /** A position, not a row reference: deleting the message it was taken from changes nothing. */
    public function test_a_context_cursor_survives_the_message_it_names_being_deleted(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 6);
        $cursor = (string) GroupTalkCursor::of($messages[2]);
        $messages[2]->delete();

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode($cursor))
            ->assertOk()
            ->json();

        $this->assertSame(['message 0', 'message 1', 'message 3', 'message 4', 'message 5'], array_column($page['messages'], 'body'));
    }

    /** The established rule for every cursor parameter: unparseable is absent, and absent is latest. */
    public function test_a_malformed_context_cursor_is_read_as_no_cursor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 60);

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=not-a-cursor")
            ->assertOk()
            ->json();

        $this->assertSame('message 59', end($page['messages'])['body']);
        $this->assertCount(GroupTalkMessages::PER_PAGE, $page['messages']);
    }

    /**
     * A cursor is a place in time, not a claim on a row: one taken from another group's message
     * names an instant here too, and the query's own group binding is what keeps the answer this
     * conversation's. Nothing leaks either way.
     */
    public function test_a_cursor_from_another_group_is_only_a_position(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 6);
        $foreign = GroupMessage::factory()->create([
            'group_id' => $elsewhere->getKey(),
            'body' => 'theirs',
            'created_at' => Carbon::parse('2026-08-14 09:02:00'),
            'updated_at' => Carbon::parse('2026-08-14 09:02:00'),
        ]);

        $page = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode((string) GroupTalkCursor::of($foreign)))
            ->assertOk()
            ->json();

        $bodies = array_column($page['messages'], 'body');
        $this->assertNotContains('theirs', $bodies);
        $this->assertSame(['message 0', 'message 1', 'message 2', 'message 3', 'message 4', 'message 5'], $bodies);
    }

    /** Pagination is a position, not a permission: a reader the gate admits may ask from anywhere. */
    public function test_a_non_member_reader_may_open_a_context_page(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $messages = $this->conversation($group, 60);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode((string) GroupTalkCursor::of($messages[20])))
            ->assertOk()
            ->assertJsonPath('messages.'.(GroupTalkMessages::CONTEXT - 1).'.body', 'message 20');
    }

    /** What "load newer" reads: the same forward page the poll takes, now saying whether it capped. */
    public function test_a_forward_page_reports_whether_more_remain_behind_it(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 80);

        $capped = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?after=".urlencode((string) GroupTalkCursor::of($messages[0])))
            ->assertOk()
            ->json();
        $this->assertCount(GroupTalkMessages::PER_PAGE, $capped['messages']);
        $this->assertTrue($capped['hasNewer']);

        $rest = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?after=".urlencode(end($capped['messages'])['cursor']))
            ->assertOk()
            ->json();
        $this->assertFalse($rest['hasNewer'], 'the conversation runs out inside this page');
    }

    /** Nothing follows the newest page, and nothing the asker lacks follows a "load older" one. */
    public function test_the_backwards_reads_report_nothing_newer_to_fetch(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 60);

        $latest = $this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk()->json();
        $this->assertFalse($latest['hasNewer']);

        $older = $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?before=".urlencode($latest['messages'][0]['cursor']))
            ->assertOk()
            ->json();
        $this->assertFalse($older['hasNewer']);
    }
}
