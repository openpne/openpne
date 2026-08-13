<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Features\GroupTalk\Queries\NavTalkRooms;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar's room list: a shared prop, so it is evaluated on every Inertia page and its cost is
 * the thing to hold down. What it must agree with is `/groups/mine` — same rooms, same order, same
 * numbers — and what it must not do is pay for the previews it does not draw.
 */
class TalkNavRoomsTest extends TalkTestCase
{
    private function joined(Member $member, ?string $name = null): Group
    {
        $group = Group::factory()->create($name === null ? [] : ['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $group;
    }

    private function say(Group $group, string $at): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    public function test_the_nav_carries_the_room_s_name_image_and_numbers(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->pictured($this->joined($viewer, 'Book club'));
        GroupMessage::factory()->count(2)->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('talkNavRooms.rooms.0.id', $group->getKey())
                ->where('talkNavRooms.rooms.0.name', 'Book club')
                // A sidebar row paints at 24px, so it asks for the smallest whitelisted size that
                // covers 2x rather than the 180 the right rail's far larger tiles took.
                ->where('talkNavRooms.rooms.0.imageUrl', $group->fresh()->image->thumbnailUrl(48, 48, square: true))
                ->where('talkNavRooms.rooms.0.unread', 2)
                ->where('talkNavRooms.rooms.0.muted', false)
                ->where('talkNavRooms.hasMore', false)
                // The row draws no preview, so none travels.
                ->missing('talkNavRooms.rooms.0.latest'));
    }

    /** Muting silences the nav badge; the room's own number stays, as it does on the joined list. */
    public function test_a_muted_room_keeps_its_number(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        DB::table('group_members')
            ->where('group_id', $group->getKey())->where('member_id', $viewer->getKey())
            ->update(['is_talk_muted' => true]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('talkNavRooms.rooms.0.muted', true)
                ->where('talkNavRooms.rooms.0.unread', 1));
    }

    /**
     * The nav is a slice of the joined list, not a second reading of the membership: the two are
     * asserted against each other so an order settled differently here would fail.
     */
    public function test_the_rooms_arrive_in_the_joined_list_s_own_order(): void
    {
        $viewer = Member::factory()->create();
        $quiet = $this->joined($viewer);
        $loud = $this->joined($viewer);
        $silent = $this->joined($viewer);
        $this->say($quiet, '2026-08-14 09:00:00');
        $this->say($loud, '2026-08-14 10:00:00');

        $expected = array_map(
            fn ($room): int => $room->group->getKey(),
            app(JoinedTalkRooms::class)($viewer)->items(),
        );

        $this->assertSame([$loud->getKey(), $quiet->getKey(), $silent->getKey()], $expected);
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where(
                'talkNavRooms.rooms',
                fn ($rooms) => $rooms->pluck('id')->all() === $expected,
            ));
    }

    public function test_the_nav_holds_ten_rooms_and_says_there_are_more(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 11) as $minute) {
            $this->say($this->joined($viewer), Carbon::parse('2026-08-14 10:00:00')->subMinutes($minute)->toDateTimeString());
        }

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('talkNavRooms.rooms', 10)
                ->where('talkNavRooms.hasMore', true));
    }

    /** The eleventh row is what answers "is there more", so exactly ten must not claim one. */
    public function test_a_full_list_with_nothing_beyond_it_says_there_is_no_more(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 10) as $ignored) {
            $this->joined($viewer);
        }

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('talkNavRooms.rooms', 10)
                ->where('talkNavRooms.hasMore', false));
    }

    public function test_a_member_in_no_group_carries_an_empty_list(): void
    {
        $this->actingAs(Member::factory()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('talkNavRooms.rooms', [])
                ->where('talkNavRooms.hasMore', false));
    }

    public function test_a_guest_carries_no_room_list(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('talkNavRooms', null));
    }

    /** A switched-off unit does not read the conversation it is hiding — on any page, not just its own. */
    public function test_talk_switched_off_carries_no_list_and_reads_no_message(): void
    {
        $viewer = Member::factory()->create();
        GroupMessage::factory()->create(['group_id' => $this->joined($viewer)->getKey()]);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        DB::flushQueryLog(); // the log survives disableQueryLog(), so a second call would stack
        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('talkNavRooms', null));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('group_messages', $query);
        }
    }

    /**
     * Two queries, whatever the list holds: the rooms with their numbers, and one batched fetch of
     * their images. This is the whole reason the nav has its own read — the joined list's five
     * include the previews and their authors, which no sidebar row draws.
     */
    public function test_the_list_costs_two_queries_however_many_rooms_it_holds(): void
    {
        $one = Member::factory()->create();
        $this->pictured($this->joined($one));

        $many = Member::factory()->create();
        foreach (range(1, 11) as $ignored) {
            $this->pictured($this->joined($many));
        }

        $small = $this->queryCount($one);

        $this->assertSame($small, $this->queryCount($many), 'the list grew a query per room');
        $this->assertSame(2, $small, 'rooms, then their images');
    }

    /** A group with an image, so the eager load is one of the queries being counted. */
    private function pictured(Group $group): Group
    {
        $group->forceFill(['file_id' => File::factory()->create()->getKey()])->save();

        return $group;
    }

    private function queryCount(Member $viewer): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(NavTalkRooms::class)($viewer);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
