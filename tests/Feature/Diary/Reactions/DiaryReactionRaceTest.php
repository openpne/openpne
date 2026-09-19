<?php

namespace Tests\Feature\Diary\Reactions;

use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\DiaryReactionSurface;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single-connection: the gate is simulated by handing the action a model whose row has already been
 * deleted. What the diary lock adds is DiaryReactionLockOrderTest's, on MySQL.
 */
class DiaryReactionRaceTest extends DiaryReactionTestCase
{
    public function test_reacting_to_an_entry_deleted_since_the_gate_writes_nothing(): void
    {
        $diary = $this->diary();
        DB::table('diaries')->where('id', $diary->getKey())->delete();

        $this->assertRefused(fn () => app(AddReaction::class)(Member::factory()->create(), $diary, $this->emoji(0), new DiaryReactionSurface));

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_reacting_to_a_comment_deleted_since_the_gate_writes_nothing(): void
    {
        $comment = $this->comment($this->diary());
        DB::table('diary_comments')->where('id', $comment->getKey())->delete();

        $this->assertRefused(fn () => app(AddReaction::class)(Member::factory()->create(), $comment, $this->emoji(0), new DiaryReactionSurface));

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_removing_from_an_entry_deleted_since_the_gate_is_refused(): void
    {
        $diary = $this->diary();
        $member = Member::factory()->create();
        $this->react($member, $diary)->assertOk();
        DB::table('diaries')->where('id', $diary->getKey())->delete();

        $this->assertRefused(fn () => app(RemoveReaction::class)($member, $diary, $this->emoji(0), new DiaryReactionSurface));
    }

    /** The comment is bound, then its diary is read: between the two the diary can go, and the comment with it by cascade. */
    public function test_a_comment_whose_entry_went_after_the_binding_is_not_found(): void
    {
        $comment = $this->comment($this->diary());
        $member = Member::factory()->create();
        $this->instance(AddReaction::class, new class extends AddReaction
        {
            public function __invoke(Member $member, $reactable, string $emoji, $surface): bool
            {
                throw new RuntimeException('the gate let a comment without an entry through');
            }
        });
        DiaryComment::retrieved(function (DiaryComment $retrieved) use ($comment): void {
            if ($retrieved->is($comment) && DB::table('diaries')->where('id', $comment->diary_id)->exists()) {
                DB::table('diaries')->where('id', $comment->diary_id)->delete();
            }
        });

        $this->react($member, $comment)->assertNotFound();
        $this->actingAs($member)->getJson("/diary/comment/{$comment->getKey()}/reactions")->assertNotFound();
    }

    public function test_a_refused_write_answers_404(): void
    {
        $diary = $this->diary();
        $this->instance(AddReaction::class, new class extends AddReaction
        {
            public function __invoke(Member $member, $reactable, string $emoji, $surface): bool
            {
                throw new ReactionRefused;
            }
        });

        $this->react(Member::factory()->create(), $diary)->assertNotFound();
    }

    public function test_a_failed_entry_delete_leaves_the_reactions_it_had_already_swept(): void
    {
        $diary = $this->diary();
        $comment = $this->comment($diary);
        $this->react(Member::factory()->create(), $diary)->assertOk();
        $this->react(Member::factory()->create(), $comment)->assertOk();

        Diary::deleting(function (): void {
            throw new RuntimeException('the delete failed after the sweep');
        });

        try {
            (new DeleteDiary)->purge($diary);
            $this->fail('the delete did not fail');
        } catch (RuntimeException) {
            // The rollback is what is under test.
        }

        $this->assertDatabaseCount('diaries', 1);
        $this->assertDatabaseCount('reactions', 2);
    }

    public function test_a_failed_comment_delete_leaves_its_reactions(): void
    {
        $comment = $this->comment($this->diary());
        $this->react(Member::factory()->create(), $comment)->assertOk();

        DiaryComment::deleting(function (): void {
            throw new RuntimeException('the delete failed after the sweep');
        });

        try {
            (new DeleteComment)->purge($comment);
            $this->fail('the delete did not fail');
        } catch (RuntimeException) {
            // The rollback is what is under test.
        }

        $this->assertDatabaseCount('diary_comments', 1);
        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_deleting_an_entry_that_is_already_gone_does_nothing(): void
    {
        $diary = $this->diary();
        $kept = $this->diary();
        $this->react(Member::factory()->create(), $kept)->assertOk();
        DB::table('diaries')->where('id', $diary->getKey())->delete();

        (new DeleteDiary)->purge($diary);

        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseCount('diaries', 1);
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
            $this->fail('the write was not refused');
        } catch (ReactionRefused) {
            $this->addToAssertionCount(1);
        }
    }
}
