<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Features\GroupTalk\TalkReadCursor;
use App\Models\UpgradeState;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass writing each migrated membership's talk read cursor as TalkReadCursor::snapshot()
 * of its group, the tuple a join writes, once the messages have landed (docs/internals/upgrade.md,
 * "Post-walk passes"). A bulk initialization before any native write, not a forward-only advance():
 * the tuple is a function of the migrated rows, so a rescan writes the same one.
 */
final class TalkReadCursorBackfill
{
    private const KEY = 'talk_read_cursor_backfill';

    public function plan(Closure $out): void
    {
        $out("PLAN would point every migrated group membership's talk read cursor at the group's latest migrated message.");
    }

    /** @param  list<string>  $targetTables  this run's step target tables (skip unless the run owns both sides) */
    public function run(array $targetTables, Closure $out): bool
    {
        if (! in_array('group_members', $targetTables, true) || ! in_array('group_messages', $targetTables, true)) {
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

            $updated = DB::transaction(function (): int {
                $updated = $this->backfill();

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

        $out('DONE '.self::KEY.": {$updated} memberships");

        return true;
    }

    /** @return int memberships written; a group with no message keeps the schema default */
    private function backfill(): int
    {
        $updated = 0;
        foreach (DB::table('group_messages')->distinct()->orderBy('group_id')->pluck('group_id') as $groupId) {
            $updated += DB::table('group_members')
                ->where('group_id', $groupId)
                ->update(TalkReadCursor::snapshot((int) $groupId));
        }

        return $updated;
    }

    private function isCompleted(): bool
    {
        return UpgradeState::query()
            ->where('step_key', self::KEY)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
