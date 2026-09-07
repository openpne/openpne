<?php

namespace App\Upgrade\Verify;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\ActivityThread;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Re-checks the backfill without trusting its checkpoint: in a group with migrated messages, each
 * membership must sit at or past the latest migrated `(created_at, id)` tuple and name a message
 * (`talk_read_message_id <> 0`). Only a join's snapshot or an advance() writes a message id, so the
 * schema default `(now, 0)` fails whatever its stamp (docs/internals/upgrade.md, "Verify").
 */
final class TalkReadCursorCheck
{
    private const SAMPLE = 5;

    /**
     * @param  list<string>  $targetTables
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(RunOptions $options, array $targetTables, Closure $record): void
    {
        if (! in_array('group_members', $targetTables, true) || ! in_array('group_messages', $targetTables, true)) {
            return;
        }

        $completed = UpgradeState::query()->where('step_key', 'talk_read_cursor_backfill')
            ->where('status', UpgradeState::STATUS_COMPLETED)->exists();
        if (! $completed) {
            $record('talk_read_cursor', false, 'not completed — no completed upgrade-state row for the cursor backfill');

            return;
        }

        $migrated = '`m`.`group_id` = `group_members`.`group_id` AND EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data')
            .' AS `a` WHERE `a`.`id` = `m`.`id` AND '.ActivityThread::landsInGroup('a').')';
        $latest = static fn (string $column): string => "(SELECT `m`.`{$column}` FROM `group_messages` AS `m` WHERE {$migrated}"
            .' ORDER BY `m`.`created_at` DESC, `m`.`id` DESC LIMIT 1)';
        $atOrPastLatest = '(`talk_read_at` > '.$latest('created_at').' OR (`talk_read_at` = '.$latest('created_at').' AND `talk_read_message_id` >= '.$latest('id').'))';
        $where = " WHERE EXISTS (SELECT 1 FROM `group_messages` AS `m` WHERE {$migrated}) AND NOT (`talk_read_message_id` <> 0 AND {$atOrPastLatest})";
        $compiler = new InsertSelectCompiler;
        $resolve = static fn (string $sql): string => $compiler->resolveSourceRefs($sql, $options->sourcePrefix, $options->sourceDatabase);

        $behind = (int) DB::scalar($resolve('SELECT COUNT(*) FROM `group_members`'.$where));
        $sample = $behind === 0 ? [] : DB::select($resolve('SELECT `group_id`, `member_id` FROM `group_members`'.$where.' ORDER BY `group_id`, `member_id` LIMIT '.self::SAMPLE));

        $record('talk_read_cursor', $behind === 0, $behind === 0
            ? 'every membership is read up to its group\'s migrated talk'
            : "{$behind} membership(s) are not read up to their group's migrated talk (e.g. group:member "
                .implode(', ', array_map(static fn (object $r): string => "{$r->group_id}:{$r->member_id}", $sample)).')');
    }
}
