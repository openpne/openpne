<?php

namespace Tests\Feature\Group\Reactions;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\BoardCommentReactionSurface;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\GroupTopic\Actions\DeleteTopicComment;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * The write is fired from inside the delete's transaction, after its sweep and before its row
 * delete, on a second connection. What is pinned is the statement the write timed out on: the
 * topic or event row, never the group row a board writer does not take.
 */
class BoardReactionLockOrderTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    public function test_a_reaction_racing_a_topic_delete_waits_at_the_topic_and_a_later_one_is_refused(): void
    {
        $comment = GroupTopicComment::factory()->create();
        $reactor = Member::factory()->create();
        $outcome = null;

        GroupTopic::deleting(function () use (&$outcome, $reactor, $comment): void {
            $outcome = $this->raceReaction($reactor, $comment);
        });

        (new DeleteTopic)->purge($comment->topic);

        $this->assertWaitedOn('group_topics', $outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $comment));
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_racing_an_event_delete_waits_at_the_event_and_a_later_one_is_refused(): void
    {
        $comment = GroupEventComment::factory()->create();
        $reactor = Member::factory()->create();
        $outcome = null;

        GroupEvent::deleting(function () use (&$outcome, $reactor, $comment): void {
            $outcome = $this->raceReaction($reactor, $comment);
        });

        (new DeleteEvent)->purge($comment->event);

        $this->assertWaitedOn('group_events', $outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $comment));
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_racing_a_comment_delete_waits_at_the_topic_and_a_later_one_is_refused(): void
    {
        $comment = GroupTopicComment::factory()->create();
        $reactor = Member::factory()->create();
        $outcome = null;

        GroupTopicComment::deleting(function () use (&$outcome, $reactor, $comment): void {
            $outcome = $this->raceReaction($reactor, $comment);
        });

        (new DeleteTopicComment)->purge($comment);

        $this->assertWaitedOn('group_topics', $outcome);
        $this->assertSame('refused', $this->raceReaction($reactor, $comment));
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * The group row alone would not stop a board writer, which never takes it: the teardown holds
     * every topic and event exclusively, and that is the row the writer waits on.
     */
    public function test_a_reaction_racing_a_group_teardown_waits_at_the_topic_not_the_group(): void
    {
        $group = Group::factory()->create();
        $topicComment = GroupTopicComment::factory()->create(['group_topic_id' => GroupTopic::factory()->create(['group_id' => $group->getKey()])->getKey()]);
        $eventComment = GroupEventComment::factory()->create(['group_event_id' => GroupEvent::factory()->create(['group_id' => $group->getKey()])->getKey()]);
        $reactor = Member::factory()->create();
        $outcomes = null;

        Group::deleting(function () use (&$outcomes, $reactor, $topicComment, $eventComment): void {
            $outcomes = [$this->raceReaction($reactor, $topicComment), $this->raceReaction($reactor, $eventComment)];
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertNotNull($outcomes, 'the race was never run');
        $this->assertWaitedOn('group_topics', $outcomes[0]);
        $this->assertWaitedOn('group_events', $outcomes[1]);
        $this->assertSame('refused', $this->raceReaction($reactor, $topicComment));
        $this->assertSame('refused', $this->raceReaction($reactor, $eventComment));
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    /**
     * Waiting on the group row or on the sweep's own gap locks is not the contract: after the delete
     * commits such an insert would go through and leave the orphan, while a wait at the parent's
     * locking read re-reads the comment afterwards and finds it gone.
     */
    private function assertWaitedOn(string $table, ?string $outcome): void
    {
        $this->assertNotNull($outcome, 'the race was never run');
        $this->assertStringStartsWith('waited:', $outcome, "the reaction did not wait ({$outcome})");
        $sql = substr($outcome, strlen('waited:'));
        $this->assertMatchesRegularExpression("/from `{$table}`.*for update/is", $sql, "waited on the wrong statement: {$sql}");
    }

    /** @return string 'written' | 'refused' | 'waited:<the statement that timed out>' */
    private function raceReaction(Member $reactor, GroupTopicComment|GroupEventComment $comment): string
    {
        return $this->onSecondConnection(function () use ($reactor, $comment): string {
            try {
                app(AddReaction::class)($reactor, $comment, ReactionVocabulary::all()[0], new BoardCommentReactionSurface);

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
