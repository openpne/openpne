<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Features\Group\BoardBumpedAt;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\UpgradeState;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass settling every migrated topic's and event's `bumped_at` from its comments, once
 * they have landed (docs/internals/upgrade.md, "Post-walk passes"). The step writes created_at as a
 * placeholder because the comment rows do not exist yet when the thread rows are inserted.
 */
final class BoardBumpBackfill
{
    public const KEY = 'board_bump_backfill';

    /** @var array<string, array{class-string, string}> thread table => [model, comments table] */
    private const BOARDS = [
        'group_topics' => [GroupTopic::class, 'group_topic_comments'],
        'group_events' => [GroupEvent::class, 'group_event_comments'],
    ];

    public function plan(Closure $out): void
    {
        $out("PLAN would settle every migrated topic's and event's bumped_at from its comments.");
    }

    /** @param  list<string>  $targetTables  this run's step target tables (skip unless the run owns a board and its comments) */
    public function run(array $targetTables, Closure $out): bool
    {
        $boards = array_filter(self::BOARDS, fn (array $board, string $table) => in_array($table, $targetTables, true) && in_array($board[1], $targetTables, true), ARRAY_FILTER_USE_BOTH);
        if ($boards === []) {
            return true;
        }

        if ($this->isCompleted()) {
            $out('SKIP '.self::KEY.': already completed');

            return true;
        }

        try {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            $updated = DB::transaction(function () use ($boards): int {
                $updated = 0;
                foreach ($boards as [$model]) {
                    $updated += BoardBumpedAt::settleAll($model);
                }

                UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => $updated,
                    'finished_at' => now(),
                ]);

                return $updated;
            });
        } catch (Throwable $e) {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out('FAIL '.self::KEY.": {$e->getMessage()}");

            return false;
        }

        $out('DONE '.self::KEY.": {$updated} threads settled");

        return true;
    }

    private function isCompleted(): bool
    {
        return UpgradeState::query()
            ->where('step_key', self::KEY)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
