<?php

namespace Tests\Feature\Diary\Reactions;

use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\DiaryReactionSurface;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionVocabulary;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * The write is fired from inside the delete's transaction, after its sweep and before its row
 * delete, on a second connection: the interleaving in which a comment-only lock would let the
 * reaction outlive the comment. What is pinned is the statement the write timed out on.
 */
class DiaryReactionLockOrderTest extends TestCase
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

    public function test_a_reaction_racing_an_entry_delete_waits_at_the_entry_and_a_later_one_is_refused(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create(['diary_id' => $diary->getKey()]);
        $reactor = Member::factory()->create();
        $outcome = null;

        Diary::deleting(function () use (&$outcome, $reactor, $comment): void {
            $outcome = $this->raceReaction($reactor, $comment);
        });

        (new DeleteDiary)->purge($diary);

        $this->assertWaitedOnTheEntry($outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $comment));
        $this->assertDatabaseCount('diaries', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The entry is posted from another device after the drain's enumeration, so only the sweep under the member row reaches it. */
    public function test_a_reaction_racing_a_withdrawal_waits_at_the_late_entry_and_a_later_one_is_refused(): void
    {
        $leaving = Member::factory()->create();
        $reactor = Member::factory()->create();
        $late = null;
        $comment = null;
        $outcome = null;

        DB::listen(function ($query) use (&$late, &$comment, $leaving): void {
            if ($late === null && $query->connectionName !== self::SECOND && $query->connection->transactionLevel() === 0 && str_contains($query->sql, 'from `diaries`')) {
                [$late, $comment] = $this->onSecondConnection(function () use ($leaving): array {
                    $diary = Diary::factory()->create(['member_id' => $leaving->getKey()]);

                    return [$diary, DiaryComment::factory()->create(['diary_id' => $diary->getKey()])];
                });
            }
        });
        Member::deleting(function (Member $member) use (&$outcome, &$comment, $leaving, $reactor): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($reactor, $comment);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertNotNull($late, 'the entry never slipped in');
        $this->assertWaitedOnTheEntry($outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $comment));
        $this->assertDatabaseMissing('diaries', ['id' => $late->getKey()]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * The reactor's own row comes before any diary lock: the withdrawal holds it exclusively while
     * it takes the entries, so a reaction that took an entry first would close a cycle with it.
     */
    public function test_a_leaving_members_own_reaction_waits_at_their_member_row_before_any_entry_lock(): void
    {
        $leaving = Member::factory()->create();
        $comment = DiaryComment::factory()->create(['diary_id' => Diary::factory()->create()->getKey()]);
        $outcome = null;

        Member::deleting(function (Member $member) use (&$outcome, $leaving, $comment): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($leaving, $comment);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertWaitedOnTheMemberRow($outcome);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_leaving_members_own_removal_waits_at_their_member_row_before_any_entry_lock(): void
    {
        $leaving = Member::factory()->create();
        $comment = DiaryComment::factory()->create(['diary_id' => Diary::factory()->create()->getKey()]);
        $comment->reactions()->create(['member_id' => $leaving->getKey(), 'emoji' => ReactionVocabulary::all()[0]]);
        $outcome = null;

        Member::deleting(function (Member $member) use (&$outcome, $leaving, $comment): void {
            if ($member->is($leaving)) {
                $outcome = $this->raceReaction($leaving, $comment, remove: true);
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertWaitedOnTheMemberRow($outcome);
    }

    /** The comment lands between the sweep's enumeration and its entry hold, where a consistent read's snapshot would hide it. */
    public function test_the_withdrawal_sweep_sees_a_comment_committed_after_its_snapshot(): void
    {
        $leaving = Member::factory()->create();
        $commenter = Member::factory()->create();
        $reactor = Member::factory()->create();
        $late = null;
        $slippedIn = null;

        DB::listen(function ($query) use (&$late, &$slippedIn, $leaving, $commenter, $reactor): void {
            if ($query->connectionName === self::SECOND || ! str_contains($query->sql, 'from `diaries`')) {
                return;
            }
            $level = $query->connection->transactionLevel();
            if ($late === null && $level === 0) {
                $late = $this->onSecondConnection(fn (): Diary => Diary::factory()->create(['member_id' => $leaving->getKey()]));

                return;
            }
            if ($late !== null && $slippedIn === null && $level >= 1 && str_contains($query->sql, 'order by')) {
                $slippedIn = $this->onSecondConnection(function () use ($late, $commenter, $reactor): int {
                    $comment = DiaryComment::factory()->create(['diary_id' => $late->getKey(), 'member_id' => $commenter->getKey()]);
                    app(AddReaction::class)($reactor, $comment, ReactionVocabulary::all()[0], new DiaryReactionSurface);

                    return (int) $comment->getKey();
                });
            }
        });

        app(WithdrawMember::class)($leaving);

        $this->assertNotNull($slippedIn, 'the comment never slipped in');
        $this->assertDatabaseMissing('diary_comments', ['id' => $slippedIn]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * Waiting anywhere else is not the contract: the sweep's own gap locks would also stall the
     * insert, but after the delete commits that insert would go through and leave the orphan, while a
     * wait at the entry's locking read re-reads the comment afterwards and finds it gone.
     */
    private function assertWaitedOnTheEntry(?string $outcome): void
    {
        $this->assertNotNull($outcome, 'the race was never run');
        $this->assertStringStartsWith('waited:', $outcome, "the reaction did not wait ({$outcome})");
        $sql = substr($outcome, strlen('waited:'));
        $this->assertMatchesRegularExpression('/from `diaries`.*for update/is', $sql, "waited on the wrong statement: {$sql}");
    }

    private function assertWaitedOnTheMemberRow(?string $outcome): void
    {
        $this->assertNotNull($outcome, 'the race was never run');
        $this->assertStringStartsWith('waited:', $outcome, "the write did not wait ({$outcome})");
        $this->assertMatchesRegularExpression('/from `members`.*lock in share mode/is', substr($outcome, strlen('waited:')), "waited on the wrong statement: {$outcome}");
    }

    /** @return string 'written' | 'refused' | 'waited:<the statement that timed out>' */
    private function raceReaction(Member $reactor, DiaryComment $comment, bool $remove = false): string
    {
        return $this->onSecondConnection(function () use ($reactor, $comment, $remove): string {
            try {
                $action = $remove ? app(RemoveReaction::class) : app(AddReaction::class);
                $action($reactor, $comment, ReactionVocabulary::all()[0], new DiaryReactionSurface);

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
