<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Features\GroupTalk\Queries\UnreadTalkCounts;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\Runner\TalkReadCursorBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The membership rows are given the walk's state — the schema default, a wall-clock stamp — so a
 * message dated after the upgrade is what tells the pass from the default.
 */
class TalkReadCursorBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $out = [];

    public function test_a_message_dated_after_the_upgrade_is_unread_until_the_pass_runs(): void
    {
        $group = Group::factory()->create();
        [$viewer, $author] = Member::factory()->count(2)->create();
        $this->walkedMembership($group, $viewer);
        $this->walkedMembership($group, $author);
        $message = GroupMessage::factory()->create(['group_id' => $group->id, 'member_id' => $author->id, 'created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);

        $this->assertSame(1, app(UnreadTalkCounts::class)($viewer)[$group->id]['count']);

        $this->assertTrue($this->runPass());

        $this->assertSame(0, app(UnreadTalkCounts::class)($viewer)[$group->id]['count']);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'member_id' => $viewer->id, 'talk_read_message_id' => $message->id]);
        $this->assertSame(2, (int) UpgradeState::where('step_key', 'talk_read_cursor_backfill')->value('rows_affected'));
        $this->assertContains('DONE talk_read_cursor_backfill: 2 memberships changed', $this->out);
    }

    public function test_a_same_second_tie_goes_to_the_higher_id_and_a_silent_group_keeps_the_default(): void
    {
        [$talking, $silent] = Group::factory()->count(2)->create();
        $member = Member::factory()->create();
        $this->walkedMembership($talking, $member);
        $this->walkedMembership($silent, $member);
        foreach ([1, 2] as $id) {
            GroupMessage::factory()->create(['id' => $id, 'group_id' => $talking->id, 'member_id' => $member->id, 'created_at' => '2015-05-06 07:08:09', 'updated_at' => '2015-05-06 07:08:09']);
        }

        $this->assertTrue($this->runPass());

        $this->assertDatabaseHas('group_members', ['group_id' => $talking->id, 'talk_read_at' => '2015-05-06 07:08:09', 'talk_read_message_id' => 2]);
        // The pass's SQL and the join-time snapshot are two spellings of one rule.
        $snapshot = TalkReadCursor::snapshot($talking->id);
        $this->assertSame(['talk_read_at' => '2015-05-06 07:08:09', 'talk_read_message_id' => 2], ['talk_read_at' => $snapshot['talk_read_at']->format('Y-m-d H:i:s'), 'talk_read_message_id' => $snapshot['talk_read_message_id']]);
        $this->assertDatabaseHas('group_members', ['group_id' => $silent->id, 'talk_read_message_id' => 0]);
    }

    public function test_skips_unless_the_run_owns_both_tables_and_after_a_completed_checkpoint(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        $this->walkedMembership($group, $member);
        GroupMessage::factory()->create(['group_id' => $group->id, 'member_id' => $member->id]);

        $this->assertTrue((new TalkReadCursorBackfill)->run(['group_members'], $this->collector()));
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'talk_read_message_id' => 0]);
        $this->assertNull(UpgradeState::where('step_key', 'talk_read_cursor_backfill')->first());

        $this->assertTrue($this->runPass());
        $later = GroupMessage::factory()->create(['group_id' => $group->id, 'member_id' => $member->id, 'created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);

        $this->assertTrue($this->runPass());

        $this->assertContains('SKIP talk_read_cursor_backfill: already completed', $this->out);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'talk_read_message_id' => $later->id]);
    }

    public function test_a_failure_after_the_write_rolls_every_cursor_back_and_records_it(): void
    {
        [$first, $second] = Group::factory()->count(2)->create();
        $member = Member::factory()->create();
        $this->walkedMembership($first, $member);
        $this->walkedMembership($second, $member);
        foreach ([$first, $second] as $group) {
            GroupMessage::factory()->create(['group_id' => $group->id, 'member_id' => $member->id, 'created_at' => '2015-05-06 07:08:09', 'updated_at' => '2015-05-06 07:08:09']);
        }
        $this->refuseTheCheckpointAfterTheWrite();

        $this->assertFalse($this->runPass());

        $this->assertDatabaseHas('group_members', ['group_id' => $first->id, 'talk_read_message_id' => 0]);
        $this->assertDatabaseHas('group_members', ['group_id' => $second->id, 'talk_read_message_id' => 0]);
        $this->assertSame(UpgradeState::STATUS_FAILED, UpgradeState::where('step_key', 'talk_read_cursor_backfill')->value('status'));
        $this->assertStringContainsString('FAIL talk_read_cursor_backfill', implode("\n", $this->out));
    }

    private function walkedMembership(Group $group, Member $member): void
    {
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $member->id]);
        DB::table('group_members')->where('group_id', $group->id)->where('member_id', $member->id)
            ->update(['talk_read_at' => now(), 'talk_read_message_id' => 0]);
    }

    /** The cursors are written, then the COMPLETED checkpoint throws: only the transaction takes the cursors back. */
    private function refuseTheCheckpointAfterTheWrite(): void
    {
        $written = false;
        $thrown = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$written, &$thrown): void {
            $written = $written || str_starts_with($query, 'UPDATE `group_members`');
            if ($written && ! $thrown && preg_match('/^(update|insert into) [`"]openpne4_upgrade_state[`"]/', $query)) {
                $thrown = true; // once: the FAILED checkpoint that follows must get through
                throw new RuntimeException('refused');
            }
        });
    }

    private function runPass(): bool
    {
        return (new TalkReadCursorBackfill)->run(['group_members', 'group_messages'], $this->collector());
    }

    private function collector(): \Closure
    {
        return function (string $line): void {
            $this->out[] = $line;
        };
    }
}
