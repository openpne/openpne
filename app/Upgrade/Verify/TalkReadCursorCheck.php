<?php

namespace App\Upgrade\Verify;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Re-checks the cursor backfill without trusting its checkpoint: no membership may sit behind its
 * group's latest migrated message (the `(created_at, id)` order a join writes), so no migrated
 * history arrives unread; messages written since, and cursors moved past, are the site's own (docs/internals/upgrade.md, "Verify").
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

        $source = InsertSelectCompiler::qualify($options->sourceDatabase, $options->sourcePrefix, 'activity_data');
        $migrated = "`m`.`group_id` = `group_members`.`group_id` AND EXISTS (SELECT 1 FROM {$source} AS `a` WHERE `a`.`id` = `m`.`id`)";
        $latest = static fn (string $column): string => "(SELECT `m`.`{$column}` FROM `group_messages` AS `m` WHERE {$migrated}"
            .' ORDER BY `m`.`created_at` DESC, `m`.`id` DESC LIMIT 1)';
        $behind = DB::select(
            'SELECT `group_id`, `member_id` FROM `group_members`'
            ." WHERE EXISTS (SELECT 1 FROM `group_messages` AS `m` WHERE {$migrated})"
            .' AND (`talk_read_at` < '.$latest('created_at').' OR (`talk_read_at` = '.$latest('created_at').' AND `talk_read_message_id` < '.$latest('id').'))'
            .' ORDER BY `group_id`, `member_id`',
        );

        $record('talk_read_cursor', $behind === [], $behind === []
            ? 'no membership is behind its group\'s latest migrated message'
            : count($behind).' membership(s) are behind their group\'s latest migrated message (e.g. group:member '
                .implode(', ', array_map(static fn (object $r): string => "{$r->group_id}:{$r->member_id}", array_slice($behind, 0, self::SAMPLE))).')');
    }
}
