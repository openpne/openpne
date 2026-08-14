<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Actions\AddMessageReaction;
use App\Features\GroupTalk\Actions\DeleteGroupMessage;
use App\Features\GroupTalk\Actions\RemoveMessageReaction;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A write is decided before it runs, and the row it was decided about can be gone by the time it
 * does. `reactions.reactable_id` carries no foreign key, so nothing at the engine level would
 * refuse the write — the actions re-read under the group's lock instead, and everything that takes
 * a message away holds that same lock first.
 *
 * The races themselves are single-connection here: the gate is simulated by handing the action a
 * model whose row has already been deleted, which is exactly what a lost race leaves it holding.
 */
class TalkReactionRaceTest extends TalkReactionTestCase
{
    public function test_reacting_to_a_message_deleted_since_the_gate_writes_nothing(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        DB::table('group_messages')->where('id', $message->getKey())->delete();

        $this->assertRefused(fn () => app(AddMessageReaction::class)($member, $message, $this->emoji(0)));

        $this->assertDatabaseCount('reactions', 0);
        $this->assertSame(0, $this->seq($group), 'a write onto a deleted message moved the version');
    }

    public function test_reacting_in_a_group_deleted_since_the_gate_writes_nothing(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        DB::table('groups')->where('id', $group->getKey())->delete();

        $this->assertRefused(fn () => app(AddMessageReaction::class)($member, $message, $this->emoji(0)));

        $this->assertDatabaseCount('reactions', 0);
    }

    /** Removing could orphan nothing, but it takes the lock in the same order, so it refuses alike. */
    public function test_removing_from_a_message_deleted_since_the_gate_is_refused(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();
        $version = $this->seq($group);
        DB::table('group_messages')->where('id', $message->getKey())->delete();

        $this->assertRefused(fn () => app(RemoveMessageReaction::class)($member, $message, $this->emoji(0)));

        $this->assertSame($version, $this->seq($group));
    }

    /** What the controller does with that refusal: the talk's usual 404, as every other one is. */
    public function test_a_refused_write_answers_the_talks_404(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->instance(AddMessageReaction::class, new class extends AddMessageReaction
        {
            public function __invoke(Member $member, GroupMessage $message, string $emoji): void
            {
                throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
            }
        });

        $this->react($member, $group, $message)->assertNotFound();
    }

    /**
     * The purge is one transaction: a failure after the reaction sweep takes the sweep with it.
     * Apart, a message could survive with its reactions already gone — or, in the other order, its
     * reactions could survive it.
     */
    public function test_a_failed_purge_leaves_the_reactions_it_had_already_swept(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();

        GroupMessage::deleting(function (): void {
            throw new RuntimeException('the delete failed after the sweep');
        });

        try {
            app(DeleteGroupMessage::class)->purge($message);
            $this->fail('the purge did not fail');
        } catch (RuntimeException) {
            // The rollback is what is under test.
        }

        $this->assertDatabaseCount('group_messages', 1);
        $this->assertDatabaseCount('reactions', 1);
    }

    /** A message another request already purged is not this one's to purge again. */
    public function test_purging_a_message_that_is_already_gone_does_nothing(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $kept = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $kept)->assertOk();
        DB::table('group_messages')->where('id', $message->getKey())->delete();

        app(DeleteGroupMessage::class)->purge($message);

        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseCount('group_messages', 1);
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
            $this->fail('the write was not refused');
        } catch (GroupTalkActionException $e) {
            $this->assertSame(GroupTalkActionFailure::UnknownMessage, $e->reason);
        }
    }
}
