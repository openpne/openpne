<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Features\Group\BoardBumpedAt;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\UpgradeState;
use App\Upgrade\Runner\BoardBumpBackfill;
use App\Upgrade\Verify\BoardBumpCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The thread rows are given the walk's state: bumped_at at created_at, the comments landed after. */
class BoardBumpBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $out = [];

    private const TABLES = ['group_topics', 'group_topic_comments', 'group_events', 'group_event_comments'];

    public function test_settles_each_thread_to_its_last_comment_or_its_own_time(): void
    {
        $group = Group::factory()->create();
        $commented = GroupTopic::factory()->create(['group_id' => $group->id, 'created_at' => '2018-01-01 09:00:00', 'bumped_at' => '2018-01-01 09:00:00']);
        GroupTopicComment::factory()->create(['group_topic_id' => $commented->id, 'number' => 1, 'created_at' => '2018-02-01 09:00:00']);
        GroupTopicComment::factory()->create(['group_topic_id' => $commented->id, 'number' => 2, 'created_at' => '2018-03-01 09:00:00']);
        $silent = GroupTopic::factory()->create(['group_id' => $group->id, 'created_at' => '2018-01-05 09:00:00', 'bumped_at' => '2018-01-05 09:00:00']);
        $event = GroupEvent::factory()->create(['group_id' => $group->id, 'created_at' => '2018-01-01 09:00:00', 'bumped_at' => '2018-01-01 09:00:00']);
        GroupEventComment::factory()->create(['group_event_id' => $event->id, 'number' => 1, 'created_at' => '2018-04-01 09:00:00']);

        $this->assertTrue($this->runPass());

        $this->assertTrue($commented->fresh()->bumped_at->equalTo('2018-03-01 09:00:00'));
        $this->assertTrue($silent->fresh()->bumped_at->equalTo('2018-01-05 09:00:00'));
        $this->assertTrue($event->fresh()->bumped_at->equalTo('2018-04-01 09:00:00'));
        $this->assertSame(3, (int) UpgradeState::where('step_key', BoardBumpBackfill::KEY)->value('rows_affected'));
        $this->assertContains('DONE board_bump_backfill: 3 threads settled', $this->out);
    }

    public function test_skips_unless_the_run_owns_a_board_and_its_comments_and_after_a_completed_checkpoint(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->id, 'created_at' => '2018-01-01 09:00:00', 'bumped_at' => '2018-01-01 09:00:00']);
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->id, 'number' => 1, 'created_at' => '2018-02-01 09:00:00']);

        $this->assertTrue((new BoardBumpBackfill)->run(['group_topics'], $this->collector()));
        $this->assertTrue($topic->fresh()->bumped_at->equalTo('2018-01-01 09:00:00'));
        $this->assertNull(UpgradeState::where('step_key', BoardBumpBackfill::KEY)->first());

        $this->assertTrue($this->runPass());
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->id, 'number' => 2, 'created_at' => '2018-05-01 09:00:00']);

        $this->assertTrue($this->runPass());

        $this->assertContains('SKIP board_bump_backfill: already completed', $this->out);
        $this->assertTrue($topic->fresh()->bumped_at->equalTo('2018-02-01 09:00:00'));
    }

    public function test_the_verifier_recomputes_the_definition_without_trusting_the_checkpoint(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->id, 'created_at' => '2018-01-01 09:00:00', 'bumped_at' => '2018-01-01 09:00:00']);
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->id, 'number' => 1, 'created_at' => '2018-02-01 09:00:00']);

        $this->assertSame([false, "1 thread(s) have a bumped_at that is not their last comment's time"], $this->check());

        $this->assertTrue($this->runPass());
        $this->assertSame([true, 'every topic and event sits at its last comment'], $this->check());

        DB::table('group_topics')->where('id', $topic->id)->update(['bumped_at' => '2018-01-01 09:00:00']);
        $this->assertSame([false, "1 thread(s) have a bumped_at that is not their last comment's time"], $this->check());
    }

    /** @return array{bool, string} */
    private function check(): array
    {
        $result = null;
        (new BoardBumpCheck)->verify(self::TABLES, function (string $name, bool $pass, string $detail) use (&$result): void {
            $result = [$pass, $detail];
        });

        return $result;
    }

    private function runPass(): bool
    {
        return (new BoardBumpBackfill)->run(self::TABLES, $this->collector());
    }

    private function collector(): \Closure
    {
        return function (string $line): void {
            $this->out[] = $line;
        };
    }

    /** The migration cannot import app code, so the two spellings of the definition are pinned equal here. */
    public function test_the_migration_backfills_with_the_same_definition_the_pass_uses(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_11_000001_replace_board_activity_key_with_bumped_at.php'));
        $template = 'UPDATE {$table} SET bumped_at = COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)';

        $this->assertStringContainsString($template, $migration);
        foreach ([['group_topics', 'group_topic_comments', 'group_topic_id'], ['group_events', 'group_event_comments', 'group_event_id']] as [$table, $comments, $fk]) {
            $expected = str_replace(['{$table}', '{$comments}', '{$fk}'], [$table, $comments, $fk], substr($template, strlen('UPDATE {$table} SET bumped_at = ')));
            $this->assertSame($expected, BoardBumpedAt::definition($table, $comments, $fk));
        }
    }
}
