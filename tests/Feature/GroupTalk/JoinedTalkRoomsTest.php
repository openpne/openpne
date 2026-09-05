<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Features\GroupTalk\Queries\NavTalkRooms;
use App\Features\GroupTalk\TalkRoom;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every ordering case is built so that the group id — what the membership grid sorted by — gives the
 * wrong answer.
 */
class JoinedTalkRoomsTest extends TalkTestCase
{
    /** A group the member has joined, optionally at a stated time (the silent rooms' tiebreak). */
    private function joined(Member $member, ?string $at = null): Group
    {
        $group = $this->group();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            ...($at === null ? [] : ['created_at' => Carbon::parse($at), 'updated_at' => Carbon::parse($at)]),
        ]);

        return $group;
    }

    private function say(Group $group, string $at, ?Member $author = null, string $body = 'hello'): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => ($author ?? Member::factory()->create())->getKey(),
            'body' => $body,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    /** @return list<int> group ids in the order the page holds them */
    private function roomIds(Member $viewer): array
    {
        return array_map(fn ($room): int => $room->group->getKey(), app(JoinedTalkRooms::class)($viewer)->items());
    }

    public function test_the_most_recently_talked_in_room_leads(): void
    {
        $viewer = Member::factory()->create();
        $loud = $this->joined($viewer);
        $quiet = $this->joined($viewer);
        $this->say($quiet, '2026-08-14 09:00:00');
        $this->say($loud, '2026-08-14 10:00:00');

        // By id the quiet room would lead; by what was last said it does not.
        $this->assertSame([$loud->getKey(), $quiet->getKey()], $this->roomIds($viewer));
    }

    /** Two rooms last spoken in during the same second, which `created_at` alone cannot order. */
    public function test_rooms_last_spoken_in_within_the_same_second_are_separated_by_message_id(): void
    {
        $viewer = Member::factory()->create();
        $earlier = $this->joined($viewer);
        $later = $this->joined($viewer);
        // The lower group id carries the higher message id, so neither the group id nor the
        // membership order can produce the expected answer.
        $this->say($later, '2026-08-14 10:00:00');
        $this->say($earlier, '2026-08-14 10:00:00');

        $this->assertSame([$earlier->getKey(), $later->getKey()], $this->roomIds($viewer));
    }

    public function test_rooms_with_nothing_said_follow_the_conversations_newest_membership_first(): void
    {
        $viewer = Member::factory()->create();
        $talked = $this->joined($viewer, '2026-08-14 12:00:00');
        $recent = $this->joined($viewer, '2026-08-13 09:00:00');
        $old = $this->joined($viewer, '2026-08-10 09:00:00');
        // The oldest conversation in the list still outranks every room that has none.
        $this->say($talked, '2026-08-01 09:00:00');

        $this->assertSame([$talked->getKey(), $recent->getKey(), $old->getKey()], $this->roomIds($viewer));
    }

    public function test_a_room_carries_only_the_viewer_s_own_groups(): void
    {
        $viewer = Member::factory()->create();
        $mine = $this->joined($viewer);
        $elsewhere = $this->group();
        $this->say($elsewhere, '2026-08-14 10:00:00');

        $this->assertSame([$mine->getKey()], $this->roomIds($viewer));
    }

    public function test_the_unread_count_holds_a_withdrawn_author_s_message_and_drops_the_viewer_s_own(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $other->getKey()]);
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey()]);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        $rooms = app(JoinedTalkRooms::class)($viewer)->items();

        $this->assertSame(2, $rooms[0]->unread);
    }

    /** The fixture names the viewer twice in one message, which is one message waiting. */
    public function test_the_mention_count_narrows_the_unread_to_what_names_the_viewer(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        $bystander = $this->memberOf($group);

        $this->names($this->said($group, $other), $viewer);
        $twice = $this->said($group, $other);
        $this->names($twice, $viewer);
        $this->names($twice, $viewer, offset: 20);
        $this->names($this->said($group, $other), $bystander);

        $room = app(JoinedTalkRooms::class)($viewer, withUnreadMentions: true)->items()[0];

        $this->assertSame(3, $room->unread);
        $this->assertSame(2, $room->unreadMentions);
    }

    /** The fixture both names and answers the viewer in one message, which counts once. */
    public function test_the_addressed_count_holds_replies_to_the_viewer_and_counts_a_message_once(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);

        $mine = $this->said($group, $viewer);
        $this->answering($group, $other, $mine);
        $both = $this->answering($group, $other, $mine);
        $this->names($both, $viewer);
        $this->names($this->said($group, $other), $viewer);
        $this->said($group, $other);

        $room = app(JoinedTalkRooms::class)($viewer, withUnreadMentions: true)->items()[0];

        $this->assertSame(4, $room->unread);
        $this->assertSame(3, $room->unreadMentions);
    }

    public function test_an_answer_to_anyone_but_the_viewer_here_is_not_addressed_to_them(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);

        $this->answering($group, $other, $this->said($group, $other));

        $elsewhere = $this->group();
        GroupMember::factory()->create(['group_id' => $elsewhere->getKey(), 'member_id' => $viewer->getKey()]);
        $this->answering($group, $other, $this->said($elsewhere, $viewer));

        $retracted = $this->said($group, $viewer);
        $this->answering($group, $other, $retracted);
        $retracted->delete();

        $rooms = collect(app(JoinedTalkRooms::class)($viewer, withUnreadMentions: true)->items())
            ->keyBy(fn (TalkRoom $room): int => $room->group->getKey());

        $this->assertSame(4, $rooms[$group->getKey()]->unread);
        $this->assertSame(0, $rooms[$group->getKey()]->unreadMentions);
    }

    /** One message in the room answering another. */
    private function answering(Group $group, Member $author, GroupMessage $parent): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'in_reply_to_id' => $parent->getKey(),
        ]);
    }

    public function test_a_room_carries_no_mention_count_unless_the_read_asked_for_one(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->names($this->said($group, $this->memberOf($group)), $viewer);

        $this->assertNull(app(JoinedTalkRooms::class)($viewer)->items()[0]->unreadMentions);
        $this->assertNull(app(JoinedTalkRooms::class)->take($viewer, 5)->first()->unreadMentions);
    }

    /** Absent from the SQL, not merely unread: the nav runs this query on every page. */
    public function test_the_mention_subselect_is_absent_unless_it_was_asked_for(): void
    {
        $viewer = Member::factory()->create();
        $this->joined($viewer);

        $rooms = app(JoinedTalkRooms::class);
        $this->assertStringNotContainsString('group_message_mentions', $rooms->ordered($viewer)->toSql());
        $this->assertStringContainsString('group_message_mentions', $rooms->ordered($viewer, true)->toSql());

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(NavTalkRooms::class)($viewer);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('group_message_mentions', $sql, 'the nav paid for a count it never draws');
        }
    }

    /** A message said just now, so it lands after the viewer's cursor and counts as unread. */
    private function said(Group $group, Member $author): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
        ]);
    }

    /** A mention row naming $member in $message, as the composer's picker writes one. */
    private function names(GroupMessage $message, Member $member, int $offset = 0): void
    {
        DB::table('group_message_mentions')->insert([
            'group_message_id' => $message->getKey(),
            'member_id' => $member->getKey(),
            'offset' => $offset,
            'length' => 1 + mb_strlen($member->name),
        ]);
    }

    public function test_a_muted_room_keeps_its_count(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);
        DB::table('group_members')
            ->where('group_id', $group->getKey())->where('member_id', $viewer->getKey())
            ->update(['is_talk_muted' => true]);

        $rooms = app(JoinedTalkRooms::class)($viewer)->items();

        $this->assertTrue($rooms[0]->muted);
        $this->assertSame(2, $rooms[0]->unread);
    }

    public function test_a_room_leads_with_the_newest_message_and_its_author(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        $author = Member::factory()->create(['name' => 'Alice']);
        $this->say($group, '2026-08-14 09:00:00', body: 'older');
        $this->say($group, '2026-08-14 10:00:00', author: $author, body: 'newest');

        $latest = app(JoinedTalkRooms::class)($viewer)->items()[0]->latest;

        $this->assertSame('newest', $latest->body);
        $this->assertSame('Alice', $latest->author->name);
    }

    public function test_a_room_with_nothing_said_leads_with_nothing(): void
    {
        $viewer = Member::factory()->create();
        $this->joined($viewer);

        $this->assertNull(app(JoinedTalkRooms::class)($viewer)->items()[0]->latest);
    }

    public function test_the_page_holds_twenty_rooms_and_the_rest_falls_to_the_next(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 21) as $minute) {
            $this->say($this->joined($viewer), Carbon::parse('2026-08-14 10:00:00')->subMinutes($minute)->toDateTimeString());
        }

        $first = app(JoinedTalkRooms::class)($viewer);
        $this->assertCount(20, $first->items());
        $this->assertSame(21, $first->total());
        $this->assertSame(2, $first->lastPage());

        Paginator::currentPageResolver(fn (): int => 2);
        $second = app(JoinedTalkRooms::class)($viewer);

        $this->assertCount(1, $second->items());
        $ids = [...$this->idsOf($first->items()), ...$this->idsOf($second->items())];
        $this->assertCount(21, array_unique($ids), 'the two pages must not overlap');
    }

    /** @param  list<TalkRoom>  $rooms */
    private function idsOf(array $rooms): array
    {
        return array_map(fn ($room): int => $room->group->getKey(), $rooms);
    }

    public function test_a_page_costs_the_same_whether_it_holds_one_room_or_twenty(): void
    {
        $one = Member::factory()->create();
        $this->say($this->pictured($this->joined($one)), '2026-08-14 10:00:00');

        $many = Member::factory()->create();
        foreach (range(1, 20) as $ignored) {
            $this->say($this->pictured($this->joined($many)), '2026-08-14 10:00:00');
        }

        $small = $this->queryCount($one);

        $this->assertSame($small, $this->queryCount($many), 'the page grew a query per room');
        $this->assertSame(5, $small, 'count, page, group images, message bodies, their authors');
    }

    /** A group with an image, so the page's eager load is one of the queries being counted. */
    private function pictured(Group $group): Group
    {
        $group->forceFill(['file_id' => File::factory()->create()->getKey()])->save();

        return $group;
    }

    private function queryCount(Member $viewer): int
    {
        DB::flushQueryLog(); // the log survives disableQueryLog(), so a second call would stack
        DB::enableQueryLog();
        app(JoinedTalkRooms::class)($viewer);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
