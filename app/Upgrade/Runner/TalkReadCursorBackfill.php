<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass writing each migrated membership's talk read cursor as the tuple a join writes,
 * TalkReadCursor::snapshot()'s `(created_at DESC, id DESC)` pick, in one statement once the messages
 * have landed (docs/internals/upgrade.md, "Post-walk passes"). A bulk initialization before any
 * native write, not a forward-only advance(): the tuple is a function of the migrated rows.
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

        $out('DONE '.self::KEY.": {$updated} memberships changed");

        return true;
    }

    /** @return int memberships written; a group with no message keeps the schema default */
    private function backfill(): int
    {
        $latest = static fn (string $column): string => "(SELECT `m`.`{$column}` FROM `group_messages` AS `m`"
            .' WHERE `m`.`group_id` = `group_members`.`group_id` ORDER BY `m`.`created_at` DESC, `m`.`id` DESC LIMIT 1)';

        return DB::update(
            'UPDATE `group_members` SET `talk_read_at` = '.$latest('created_at').', `talk_read_message_id` = '.$latest('id')
            .' WHERE EXISTS (SELECT 1 FROM `group_messages` AS `m` WHERE `m`.`group_id` = `group_members`.`group_id`)'
        );
    }

    private function isCompleted(): bool
    {
        return UpgradeState::query()
            ->where('step_key', self::KEY)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
