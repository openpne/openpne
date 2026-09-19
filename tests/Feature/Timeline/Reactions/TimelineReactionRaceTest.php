<?php

namespace Tests\Feature\Timeline\Reactions;

use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\TimelineReactionSurface;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single-connection: the gate is simulated by handing the action a model whose row has already been
 * deleted. What the root lock adds is TimelineReactionLockOrderTest's, on MySQL.
 */
class TimelineReactionRaceTest extends TimelineReactionTestCase
{
    public function test_reacting_to_a_post_deleted_since_the_gate_writes_nothing(): void
    {
        $post = $this->rootPost();
        DB::table('timeline_posts')->where('id', $post->getKey())->delete();

        $this->assertRefused(fn () => app(AddReaction::class)(Member::factory()->create(), $post, $this->emoji(0), new TimelineReactionSurface));

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_reacting_to_a_reply_whose_root_is_gone_writes_nothing(): void
    {
        $root = $this->rootPost();
        $reply = $this->reply($root);
        // Detach rather than delete: a reply outliving its root is what a lost race leaves behind.
        DB::table('timeline_posts')->where('id', $reply->getKey())->update(['in_reply_to_id' => null]);
        DB::table('timeline_posts')->where('id', $root->getKey())->delete();

        $this->assertRefused(fn () => app(AddReaction::class)(Member::factory()->create(), $reply, $this->emoji(0), new TimelineReactionSurface));

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_removing_from_a_post_deleted_since_the_gate_is_refused(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();
        $this->react($member, $post)->assertOk();
        DB::table('timeline_posts')->where('id', $post->getKey())->delete();

        $this->assertRefused(fn () => app(RemoveReaction::class)($member, $post, $this->emoji(0), new TimelineReactionSurface));
    }

    public function test_a_refused_write_answers_404(): void
    {
        $post = $this->rootPost();
        $this->instance(AddReaction::class, new class extends AddReaction
        {
            public function __invoke(Member $member, $reactable, string $emoji, $surface): bool
            {
                throw new ReactionRefused;
            }
        });

        $this->react(Member::factory()->create(), $post)->assertNotFound();
    }

    public function test_a_failed_delete_leaves_the_reactions_it_had_already_swept(): void
    {
        $post = $this->rootPost();
        $this->react(Member::factory()->create(), $post)->assertOk();

        TimelinePost::deleting(function (): void {
            throw new RuntimeException('the delete failed after the sweep');
        });

        try {
            (new DeleteTimelinePost)($post);
            $this->fail('the delete did not fail');
        } catch (RuntimeException) {
            // The rollback is what is under test.
        }

        $this->assertDatabaseCount('timeline_posts', 1);
        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_deleting_a_post_that_is_already_gone_does_nothing(): void
    {
        $post = $this->rootPost();
        $kept = $this->rootPost();
        $this->react(Member::factory()->create(), $kept)->assertOk();
        DB::table('timeline_posts')->where('id', $post->getKey())->delete();

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseCount('timeline_posts', 1);
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
