<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class TalkAnchorTest extends TalkTestCase
{
    /** @return list<GroupMessage> $count messages, one per minute, oldest first */
    private function conversation(Group $group, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $at = Carbon::parse('2026-08-14 09:00:00')->addMinutes($i);
            $messages[] = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'body' => "message {$i}",
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        // Factory memberships take talk_read_at from a DB default no time mock reaches, so every
        // member of the room is pinned behind these literal instants.
        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->update(['talk_read_at' => Carbon::parse('2026-08-14 08:00:00'), 'talk_read_message_id' => 0]);

        return $messages;
    }

    public function test_the_page_opens_on_the_slice_the_named_message_sits_in(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 80);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m={$messages[19]->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor.messageId', (int) $messages[19]->getKey())
                // The context page: the last ten at or before the message, then what follows it.
                ->where('page.messages.0.body', 'message 10')
                ->where('page.messages.'.(GroupTalkMessages::CONTEXT - 1).'.body', 'message 19')
                ->where('page.messages.'.GroupTalkMessages::CONTEXT.'.body', 'message 20')
                ->where('page.hasOlder', true)
                ->where('page.hasNewer', true));
    }

    public function test_a_link_to_a_recent_message_lands_in_a_page_that_runs_to_the_newest(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 12);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m={$messages[10]->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor.messageId', (int) $messages[10]->getKey())
                ->where('page.hasNewer', false)
                // The ten before it, the message itself, and the one that followed — nothing beyond.
                ->where('page.messages.'.(GroupTalkMessages::CONTEXT - 1).'.body', 'message 10')
                ->count('page.messages', GroupTalkMessages::CONTEXT + 1));
    }

    public function test_an_ordinary_visit_carries_no_anchor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 3);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.2.body', 'message 2'));
    }

    /**
     * Unlike a pagination cursor, an id from elsewhere is not a position to borrow: it would open
     * this group around another group's instant.
     */
    public function test_a_message_from_another_group_is_no_anchor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 60);
        $foreign = GroupMessage::factory()->create([
            'group_id' => $this->group()->getKey(),
            'body' => 'theirs',
            'created_at' => Carbon::parse('2026-08-14 09:02:00'),
            'updated_at' => Carbon::parse('2026-08-14 09:02:00'),
        ]);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m={$foreign->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.'.(GroupTalkMessages::PER_PAGE - 1).'.body', 'message 59'));
    }

    public function test_a_deleted_message_is_no_anchor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 60);
        $gone = $messages[19]->getKey();
        $messages[19]->delete();

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m={$gone}")
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.'.(GroupTalkMessages::PER_PAGE - 1).'.body', 'message 59'));
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
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->conversation($group, 3);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m=".urlencode($id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('anchor', null)
                ->where('page.messages.2.body', 'message 2'));
    }

    public function test_the_read_gate_is_unmoved_by_an_anchor(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $messages = $this->conversation($group, 3);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/talk?m={$messages[0]->getKey()}")
            ->assertNotFound();
    }

    /** Landing on a mention behind the boundary must not report the backlog as smaller than it is. */
    public function test_the_unread_snapshot_is_independent_of_the_anchor(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->conversation($group, 30);
        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $messages[9]->getKey()])
            ->assertNoContent();

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk?m={$messages[14]->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('talkUnreadSnapshot.count', 20)
                ->where('talkUnreadSnapshot.readThrough.id', (int) $messages[9]->getKey()));
    }
}
