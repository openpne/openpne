<?php

namespace Tests\Feature\GroupTalk;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which of the two shapes /groups/mine takes. The room list is the viewer's own conversations under
 * Modern with talk on; every other reading of the same route — another member's memberships,
 * Classic, talk switched off — keeps the membership grid, and must not read a message to do it.
 */
class TalkRoomListTest extends TalkTestCase
{
    private function joined(Member $member, ?string $name = null): Group
    {
        $group = Group::factory()->create($name === null ? [] : ['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $group;
    }

    /** @return list<string> the SQL run while serving $uri */
    private function queriesFor(Member $viewer, string $uri): array
    {
        DB::flushQueryLog(); // the log survives disableQueryLog(), so a second call would stack
        DB::enableQueryLog();
        $this->actingAs($viewer)->get($uri)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    public function test_the_viewer_s_own_list_is_the_room_list(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer, 'Book club');
        $author = Member::factory()->create(['name' => 'Alice']);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'see you there',
        ]);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->component('community/list')
                ->where('view', 'rooms')
                ->where('isOwner', true)
                ->where('rooms.data.0.id', $group->getKey())
                ->where('rooms.data.0.name', 'Book club')
                ->where('rooms.data.0.unread', 2)
                ->where('rooms.data.0.muted', false)
                ->where('rooms.data.0.latest.body', 'see you there')
                ->where('rooms.data.0.latest.authorName', 'Alice')
                ->has('rooms.meta'));
    }

    /** A row whose newest message is pictures alone says so, rather than trailing off after "Alice: ". */
    public function test_a_picture_only_message_previews_as_a_picture(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);

        $this->actingAs($viewer)
            ->post("/groups/{$group->getKey()}/talk", ['images' => [UploadedFile::fake()->image('shot.png', 40, 40)]])
            ->assertCreated();

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page->where('rooms.data.0.latest.body', __('Image')));
    }

    /** "0" is a message. The fallback to the stand-in tests emptiness strictly, not PHP's truthiness. */
    public function test_a_body_of_zero_is_previewed_as_itself(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey(), 'body' => '0']);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page->where('rooms.data.0.latest.body', '0'));
    }

    public function test_the_rooms_arrive_in_last_spoken_order(): void
    {
        $viewer = Member::factory()->create();
        $loud = $this->joined($viewer);
        $quiet = $this->joined($viewer);
        GroupMessage::factory()->create([
            'group_id' => $quiet->getKey(), 'created_at' => Carbon::parse('2026-08-14 09:00:00'),
            'updated_at' => Carbon::parse('2026-08-14 09:00:00'),
        ]);
        GroupMessage::factory()->create([
            'group_id' => $loud->getKey(), 'created_at' => Carbon::parse('2026-08-14 10:00:00'),
            'updated_at' => Carbon::parse('2026-08-14 10:00:00'),
        ]);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->where('rooms.data.0.id', $loud->getKey())
                ->where('rooms.data.1.id', $quiet->getKey()));
    }

    public function test_a_room_with_nothing_said_carries_no_preview(): void
    {
        $viewer = Member::factory()->create();
        $this->joined($viewer);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page->where('rooms.data.0.latest', null));
    }

    public function test_a_withdrawn_author_previews_with_no_name(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'still here']);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->where('rooms.data.0.latest.body', 'still here')
                ->where('rooms.data.0.latest.authorName', null));
    }

    /** The row is one line: a body written over several must not be able to grow it. */
    public function test_the_preview_is_one_line(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'body' => "first\n\nsecond   third"]);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page->where('rooms.data.0.latest.body', 'first second third'));
    }

    /** Another member's memberships: their order is not the viewer's conversation, so it stays a grid. */
    public function test_another_member_s_list_keeps_the_grid(): void
    {
        $owner = Member::factory()->create();
        $group = $this->joined($owner);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/groups/mine?id={$owner->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('community/list')
                ->where('view', 'grid')
                ->where('isOwner', false)
                ->where('groups.data.0.id', $group->getKey())
                ->has('groups.data.0.memberCount')
                ->missing('talkUnread'));
    }

    public function test_the_grid_returns_when_talk_is_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->component('community/list')
                ->where('view', 'grid')
                ->where('isOwner', true)
                ->where('groups.data.0.id', $group->getKey())
                ->missing('talkUnread'));
    }

    /** A switched-off unit does not read the conversation it is hiding — nor does anything Classic. */
    public function test_no_message_is_read_while_talk_is_off_or_the_surface_is_classic(): void
    {
        $viewer = Member::factory()->create();
        $group = $this->joined($viewer);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();
        foreach ($this->queriesFor($viewer, '/groups/mine') as $query) {
            $this->assertStringNotContainsString('group_messages', $query);
        }

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        $this->freshRequestState();
        config(['openpne.surface_mode' => 'classic_default']);
        foreach ($this->queriesFor($viewer, '/groups/mine') as $query) {
            $this->assertStringNotContainsString('group_messages', $query);
        }
    }

    public function test_classic_still_renders_the_membership_list(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $viewer = Member::factory()->create();
        $this->joined($viewer, 'Book club');

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertOk()
            ->assertSee('Book club');
    }
}
