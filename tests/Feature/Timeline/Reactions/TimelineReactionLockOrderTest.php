<?php

namespace Tests\Feature\Timeline\Reactions;

use App\Features\Member\Actions\WithdrawMember;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionVocabulary;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\TimelineReactionSurface;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * The write is fired from inside the delete's transaction, after its sweep and before its row
 * delete, on a second connection: the interleaving in which a reply-only lock would let the
 * reaction outlive the reply. What is pinned is the statement the write timed out on.
 */
class TimelineReactionLockOrderTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, OpensSecondConnection;

    private const LOCK_WAIT = 1205;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
        // Reserve id 1: the un-withdrawable primary member.
        Member::factory()->create();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    public function test_a_reaction_racing_a_thread_delete_waits_at_the_root_and_a_later_one_is_refused(): void
    {
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create();
        $reactor = Member::factory()->create();
        $outcome = null;

        TimelinePost::deleting(function () use (&$outcome, $reactor, $reply): void {
            $outcome = $this->raceReaction($reactor, $reply);
        });

        (new DeleteTimelinePost)($root);

        $this->assertWaitedOnTheRoot($outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $reply));
        $this->assertDatabaseCount('timeline_posts', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_racing_a_withdrawal_waits_at_the_root_and_a_later_one_is_refused(): void
    {
        $leaving = Member::factory()->create();
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $leaving->getKey()]);
        $reactor = Member::factory()->create();
        $outcome = null;

        Member::deleting(function (Member $member) use (&$outcome, $leaving, $reactor, $reply): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($reactor, $reply);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertWaitedOnTheRoot($outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $reply));
        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * The reactor's own row comes before any thread lock: the withdrawal holds it exclusively while
     * it takes the roots, so a reaction that took a root first would close a cycle with it.
     */
    public function test_a_leaving_members_own_reaction_waits_at_their_member_row_before_any_thread_lock(): void
    {
        $leaving = Member::factory()->create();
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $leaving->getKey()]);
        $outcome = null;

        Member::deleting(function (Member $member) use (&$outcome, $leaving, $reply): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($leaving, $reply);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertNotNull($outcome);
        $this->assertStringStartsWith('waited:', $outcome, "the reaction did not wait ({$outcome})");
        $this->assertMatchesRegularExpression('/from `members`.*lock in share mode/is', substr($outcome, strlen('waited:')), "waited on the wrong statement: {$outcome}");
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_leaving_members_own_removal_waits_at_their_member_row_before_any_thread_lock(): void
    {
        $leaving = Member::factory()->create();
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $leaving->getKey()]);
        $reply->reactions()->create(['member_id' => $leaving->getKey(), 'emoji' => ReactionVocabulary::all()[0]]);
        $outcome = null;

        Member::deleting(function (Member $member) use (&$outcome, $leaving, $reply): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($leaving, $reply, remove: true);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertNotNull($outcome);
        $this->assertStringStartsWith('waited:', $outcome, "the removal did not wait ({$outcome})");
        $this->assertMatchesRegularExpression('/from `members`.*lock in share mode/is', substr($outcome, strlen('waited:')), "waited on the wrong statement: {$outcome}");
    }

    /** The reply lands between the sweep's enumeration and its thread hold, where a consistent read's snapshot would hide it. */
    public function test_the_withdrawal_sweep_sees_a_reply_committed_after_its_snapshot(): void
    {
        $leaving = Member::factory()->create();
        $replier = Member::factory()->create();
        $reactor = Member::factory()->create();
        $late = null;
        $slippedIn = null;

        DB::listen(function ($query) use (&$late, &$slippedIn, $leaving, $replier, $reactor): void {
            if ($query->connectionName === self::SECOND) {
                return;
            }
            $level = $query->connection->transactionLevel();
            if ($late === null && $level === 0 && str_contains($query->sql, 'timeline_posts') && str_contains($query->sql, 'is null')) {
                $late = $this->onSecondConnection(fn (): TimelinePost => TimelinePost::factory()->create(['member_id' => $leaving->getKey()]));

                return;
            }
            if ($late !== null && $slippedIn === null && $level >= 1 && str_contains($query->sql, 'coalesce(in_reply_to_id, id)')) {
                $slippedIn = $this->onSecondConnection(function () use ($late, $replier, $reactor): int {
                    $reply = TimelinePost::factory()->replyTo($late)->create(['member_id' => $replier->getKey()]);
                    app(AddReaction::class)($reactor, $reply, ReactionVocabulary::all()[0], new TimelineReactionSurface);

                    return (int) $reply->getKey();
                });
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertNotNull($slippedIn, 'the reply never slipped in');
        $this->assertDatabaseMissing('timeline_posts', ['id' => $slippedIn]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * Waiting anywhere else is not the contract: the sweep's own gap locks would also stall the
     * insert, but after the delete commits that insert would go through and leave the orphan, while a
     * wait at the root's locking read re-reads the reply afterwards and finds it gone.
     */
    private function assertWaitedOnTheRoot(?string $outcome): void
    {
        $this->assertNotNull($outcome, 'the race was never run');
        $this->assertStringStartsWith('waited:', $outcome, "the reaction did not wait ({$outcome})");
        $sql = substr($outcome, strlen('waited:'));
        $this->assertMatchesRegularExpression('/timeline_posts.*in_reply_to_id.*is null.*for update/is', $sql, "waited on the wrong statement: {$sql}");
    }

    /** @return string 'written' | 'refused' | 'waited:<the statement that timed out>' */
    private function raceReaction(Member $reactor, TimelinePost $reply, bool $remove = false): string
    {
        return $this->onSecondConnection(function () use ($reactor, $reply, $remove): string {
            try {
                $action = $remove ? app(RemoveReaction::class) : app(AddReaction::class);
                $action($reactor, $reply, ReactionVocabulary::all()[0], new TimelineReactionSurface);

                return 'written';
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== self::LOCK_WAIT) {
                    throw $e;
                }

                return 'waited:'.$e->getSql();
            } catch (ReactionRefused) {
                return 'refused';
            }
        });
    }
}
