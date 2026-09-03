<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\CountGroupsWithUnreadTalk;
use App\Features\GroupTalk\Queries\UnreadTalkCounts;
use App\Features\Home\UnreadCounts;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;

/**
 * What counts as unread, and the two numbers built from it: the per-group count on the group list,
 * and the nav badge's count of groups with something waiting.
 */
class UnreadTalkTest extends TalkTestCase
{
    private function countsFor(Member $viewer): array
    {
        return app(UnreadTalkCounts::class)($viewer);
    }

    private function navCount(Member $viewer): int
    {
        return app(CountGroupsWithUnreadTalk::class)($viewer);
    }

    /** Somebody else wrote it, and it is newer than the cursor. */
    public function test_another_member_s_message_is_unread(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->assertSame(2, $this->countsFor($viewer)[$group->getKey()]['count']);
        $this->assertSame(1, $this->navCount($viewer));
    }

    public function test_your_own_messages_are_never_unread(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(3)->create([
            'group_id' => $group->getKey(),
            'member_id' => $viewer->getKey(),
        ]);

        $this->assertSame(0, $this->countsFor($viewer)[$group->getKey()]['count']);
        $this->assertSame(0, $this->navCount($viewer));
    }

    /**
     * The load-bearing NULL arm. `member_id != ?` is UNKNOWN for a withdrawn author's row, so
     * without the explicit IS NULL the count would silently skip exactly the messages the talk page
     * still shows under "Withdrawn member".
     */
    public function test_a_withdrawn_author_s_message_still_counts_as_unread(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey()]);

        $this->assertSame(1, $this->countsFor($viewer)[$group->getKey()]['count']);
        $this->assertSame(1, $this->navCount($viewer));
    }

    public function test_messages_at_or_before_the_cursor_are_read(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = GroupMessage::factory()->count(3)->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $messages[1]->getKey()])
            ->assertNoContent();

        $this->assertSame(1, $this->countsFor($viewer)[$group->getKey()]['count']);
    }

    /** The cursor holds copied values, not a reference, so deleting the message it names is a no-op. */
    public function test_deleting_the_message_the_cursor_names_leaves_the_count_alone(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        $messages = GroupMessage::factory()->count(3)->create([
            'group_id' => $group->getKey(),
            'member_id' => $other->getKey(),
        ]);

        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $messages[1]->getKey()])
            ->assertNoContent();
        $messages[1]->delete();

        $this->assertSame(1, $this->countsFor($viewer)[$group->getKey()]['count'], 'only the surviving newer message is unread');
    }

    public function test_a_non_member_has_no_row_and_so_no_unread(): void
    {
        $group = $this->group();
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $outsider = Member::factory()->create();

        $this->assertSame([], $this->countsFor($outsider));
        $this->assertSame(0, $this->navCount($outsider));
    }

    /** Mute is about the nav badge, not about hiding the conversation's own number. */
    public function test_muting_drops_a_group_from_the_nav_badge_but_keeps_its_own_count(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true])
            ->assertNoContent();

        $this->assertSame(0, $this->navCount($viewer), 'a muted group is not what the nav badge is for');
        $counts = $this->countsFor($viewer);
        $this->assertSame(2, $counts[$group->getKey()]['count']);
        $this->assertTrue($counts[$group->getKey()]['muted']);
    }

    public function test_the_nav_badge_counts_groups_not_messages(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 2) as $i) {
            $group = $this->group();
            DB::table('group_members')->insert([
                'group_id' => $group->getKey(), 'member_id' => $viewer->getKey(), 'role' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            GroupMessage::factory()->count(4)->create([
                'group_id' => $group->getKey(),
                'member_id' => $this->memberOf($group)->getKey(),
            ]);
        }

        $this->assertSame(2, $this->navCount($viewer), 'two rooms with something new, not eight messages');
    }

    public function test_one_group_s_messages_never_count_toward_another(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create(['group_id' => $elsewhere->getKey()]);

        $this->assertSame(0, $this->countsFor($viewer)[$group->getKey()]['count']);
        $this->assertSame(0, $this->navCount($viewer));
    }

    public function test_the_per_group_counts_are_one_query(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 5) as $i) {
            $group = Group::factory()->create();
            DB::table('group_members')->insert([
                'group_id' => $group->getKey(), 'member_id' => $viewer->getKey(), 'role' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        }

        DB::enableQueryLog();
        $this->countsFor($viewer);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, "five memberships cost {$queries} queries");
    }

    public function test_the_shared_badge_reports_zero_while_the_unit_is_off(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $this->assertSame(1, app(UnreadCounts::class)->for($viewer)['groupTalks']);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->assertSame(0, app(UnreadCounts::class)->for($viewer)['groupTalks']);
    }

    public function test_the_shared_props_and_endpoint_carry_the_count(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->getJson('/unread-counts')->assertJsonPath('unread.groupTalks', 1);
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('unread.groupTalks', 1));
    }

    public function test_the_room_list_carries_each_group_s_own_count(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);

        $this->actingAs($viewer)->get('/groups/mine')
            ->assertInertia(fn ($page) => $page
                ->where('rooms.data.0.id', $group->getKey())
                ->where('rooms.data.0.unread', 2)
                ->where('rooms.data.0.muted', false));
    }

    /** Another member's group list is not the viewer's unread; the grid ships no such prop. */
    public function test_someone_else_s_group_list_carries_no_unread(): void
    {
        $group = $this->group();
        $owner = $this->memberOf($group);
        $viewer = Member::factory()->create();
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($viewer)->get("/groups/mine?id={$owner->getKey()}")
            ->assertInertia(fn ($page) => $page->missing('talkUnread'));
    }
}
