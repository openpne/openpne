<?php

namespace App\Upgrade\Verify;

use App\Features\Group\BoardBumpedAt;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\UpgradeState;
use App\Upgrade\Runner\BoardBumpBackfill;
use Closure;

/**
 * Re-checks the backfill without trusting its checkpoint: every thread's `bumped_at` must equal the
 * definition `COALESCE(MAX(comments.created_at), created_at)` (docs/internals/upgrade.md, "Verify").
 */
final class BoardBumpCheck
{
    /** @var array<string, array{class-string, string}> thread table => [model, comments table] */
    private const BOARDS = [
        'group_topics' => [GroupTopic::class, 'group_topic_comments'],
        'group_events' => [GroupEvent::class, 'group_event_comments'],
    ];

    /**
     * @param  list<string>  $targetTables
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(array $targetTables, Closure $record): void
    {
        $boards = array_filter(self::BOARDS, fn (array $board, string $table) => in_array($table, $targetTables, true) && in_array($board[1], $targetTables, true), ARRAY_FILTER_USE_BOTH);
        if ($boards === []) {
            return;
        }

        $completed = UpgradeState::query()->where('step_key', BoardBumpBackfill::KEY)
            ->where('status', UpgradeState::STATUS_COMPLETED)->exists();
        if (! $completed) {
            $record('board_bump', false, 'not completed — no completed upgrade-state row for the board bump backfill');

            return;
        }

        $drift = 0;
        foreach ($boards as [$model]) {
            $drift += BoardBumpedAt::drift($model);
        }

        $record('board_bump', $drift === 0, $drift === 0
            ? 'every topic and event sits at its last comment'
            : "{$drift} thread(s) have a bumped_at that is not their last comment's time");
    }
}
