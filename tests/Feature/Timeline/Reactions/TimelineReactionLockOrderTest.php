<?php

namespace Tests\Feature\Timeline\Reactions;

use App\Features\Member\Actions\WithdrawMember;
use App\Features\Reactions\Actions\AddReaction;
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
 * The write is fired from inside the delete's transaction — after its sweep, before its row
 * delete — on a second connection, which is the interleaving a single-connection test cannot make:
 * a reaction that took only the reply's lock would commit there and outlive the reply.
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

    public function test_a_reaction_racing_a_thread_delete_waits_on_the_root_and_finds_the_reply_gone(): void
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

    public function test_a_reaction_racing_a_withdrawal_waits_on_the_thread_and_finds_the_reply_gone(): void
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
    private function raceReaction(Member $reactor, TimelinePost $reply): string
    {
        return $this->onSecondConnection(function () use ($reactor, $reply): string {
            try {
                app(AddReaction::class)($reactor, $reply, ReactionVocabulary::all()[0], new TimelineReactionSurface);

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
