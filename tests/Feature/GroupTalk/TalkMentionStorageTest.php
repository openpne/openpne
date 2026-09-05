<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class TalkMentionStorageTest extends TalkTestCase
{
    /** @return array{0: Group, 1: Member, 2: Member} group, author, mentionable member */
    private function conversation(string $targetName = 'Bob'): array
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = Member::factory()->create(['name' => $targetName]);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $target->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$group, $author, $target];
    }

    private function say(Member $author, $group, string $body, array $mentions): TestResponse
    {
        return $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => $body, 'mentions' => $mentions]);
    }

    public function test_a_picked_mention_is_stored_as_a_range(): void
    {
        [$group, $author, $target] = $this->conversation();

        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('group_message_mentions', [
            'group_message_id' => $id,
            'member_id' => $target->getKey(),
            'offset' => 3,
            'length' => 4,
        ]);
    }

    public function test_the_body_keeps_the_handle_as_plain_text(): void
    {
        [$group, $author, $target] = $this->conversation();

        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->json('id');

        $this->assertSame('hi @Bob welcome', GroupMessage::findOrFail($id)->body);
    }

    /** The picker chose a name that is no longer theirs: the range stops reading as the handle. */
    public function test_a_renamed_member_is_dropped_and_the_message_still_posts(): void
    {
        [$group, $author, $target] = $this->conversation();
        $target->forceFill(['name' => 'Robert'])->save();

        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('group_messages', ['id' => $id, 'body' => 'hi @Bob welcome']);
        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_a_range_past_the_end_of_the_body_is_dropped(): void
    {
        [$group, $author, $target] = $this->conversation();

        $id = $this->say($author, $group, 'hi', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('group_messages', ['id' => $id, 'body' => 'hi']);
        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_a_non_member_target_is_dropped(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $outsider = Member::factory()->create(['name' => 'Bob']);

        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $outsider->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('group_messages', ['id' => $id]);
        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_a_banned_target_is_dropped(): void
    {
        [$group, $author, $target] = $this->conversation();
        $target->forceFill(['is_login_rejected' => true])->save();

        $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated();

        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_mentioning_yourself_is_dropped(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $author->forceFill(['name' => 'Bob'])->save();

        $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $author->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated();

        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    /** @return array<string, array{0: bool}> */
    public static function blockDirections(): array
    {
        return ['the author blocked them' => [true], 'they blocked the author' => [false]];
    }

    #[DataProvider('blockDirections')]
    public function test_a_block_in_either_direction_drops_the_row(bool $authorBlocks): void
    {
        [$group, $author, $target] = $this->conversation();
        DB::table('member_blocks')->insert($authorBlocks
            ? ['blocker_id' => $author->getKey(), 'blocked_id' => $target->getKey()]
            : ['blocker_id' => $target->getKey(), 'blocked_id' => $author->getKey()]);

        $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->assertCreated();

        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_an_overlapping_row_is_dropped(): void
    {
        [$group, $author, $target] = $this->conversation();

        $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
            ['member_id' => $target->getKey(), 'offset' => 4, 'length' => 3],
        ])->assertCreated();

        $this->assertDatabaseCount('group_message_mentions', 1);
    }

    public function test_deleting_the_message_removes_its_mentions(): void
    {
        [$group, $author, $target] = $this->conversation();
        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->json('id');

        GroupMessage::findOrFail($id)->delete();

        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_deleting_the_member_removes_the_mention_and_keeps_the_message(): void
    {
        [$group, $author, $target] = $this->conversation();
        $id = $this->say($author, $group, 'hi @Bob welcome', [
            ['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4],
        ])->json('id');

        $target->delete();

        $this->assertDatabaseHas('group_messages', ['id' => $id]);
        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    public function test_the_serializer_ships_the_ranges_in_body_order(): void
    {
        [$group, $author, $target] = $this->conversation();
        $second = Member::factory()->create(['name' => 'Cid']);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $second->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->say($author, $group, '@Cid and @Bob', [
            ['member_id' => $target->getKey(), 'offset' => 9, 'length' => 4],
            ['member_id' => $second->getKey(), 'offset' => 0, 'length' => 4],
        ])->assertCreated();

        $this->actingAs($author)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->where('page.messages.0.mentions.0.memberId', $second->getKey())
                ->where('page.messages.0.mentions.0.offset', 0)
                ->where('page.messages.0.mentions.1.memberId', $target->getKey())
                ->where('page.messages.0.mentions.1.offset', 9));
    }

    public function test_a_message_without_mentions_ships_an_empty_list(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('page.messages.0.mentions', []));
    }

    public function test_a_required_mention_that_drops_takes_the_whole_message_with_it(): void
    {
        [$group, $author, $target] = $this->conversation();
        $target->forceFill(['is_login_rejected' => true])->save();

        $rows = [['member_id' => $target->getKey(), 'offset' => 3, 'length' => 4]];

        try {
            app(CreateGroupMessage::class)($author, $group, 'hi @Bob welcome', $rows, mentionsRequired: true);
            $this->fail('the write should have refused a mention it could not resolve');
        } catch (GroupTalkActionException $e) {
            $this->assertSame(GroupTalkActionFailure::MentionDropped, $e->reason);
        }

        // Rolled back, not merely refused before the insert.
        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('group_message_mentions', 0);

        // The picker's path is untouched: the handle there is the member's own text, so the row goes
        // and the message stays.
        $message = app(CreateGroupMessage::class)($author, $group, 'hi @Bob welcome', $rows);

        $this->assertDatabaseHas('group_messages', ['id' => $message->getKey(), 'body' => 'hi @Bob welcome']);
        $this->assertDatabaseCount('group_message_mentions', 0);
    }

    /** Offsets are code points, so an astral emoji before the handle must not shift the range. */
    public function test_offsets_are_counted_in_code_points(): void
    {
        [$group, $author, $target] = $this->conversation();

        $id = $this->say($author, $group, '🙂 @Bob', [
            ['member_id' => $target->getKey(), 'offset' => 2, 'length' => 4],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('group_message_mentions', ['group_message_id' => $id, 'offset' => 2]);
    }
}
