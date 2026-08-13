<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass that rewrites OpenPNE 3 carrier-emoji codes ([i:N] / [e:N] / [s:N]) to Unicode in
 * every migrated member-authored text column (EmojiMap::convert). The rewrite is a per-row PHP
 * transform, not expressible in an INSERT...SELECT, so — like PasswordWrap — it runs after the walk,
 * once every text table is populated.
 *
 * Unlike PasswordWrap it cannot re-query a predicate down to empty: 16 carrier-logo ids have no
 * Unicode equivalent and stay literal by design, so a "contains a code" predicate never drains.
 * Progress is an id cursor instead. Each chunk is `id > cursor`, narrowed by a code REGEXP, and the
 * cursor advances to the chunk's last id whether or not any row changed — an unmapped code is passed
 * over exactly once and never re-seen. The cursor is persisted as metadata->last_id after each chunk
 * commits; a RUNNING / FAILED checkpoint resumes from it. The transform is idempotent either way —
 * mapped codes vanish on the first pass and unmapped codes are no-ops — so restarting a checkpoint
 * from 0 is equally safe.
 *
 * MySQL only (the REGEXP narrowing and the utf8mb4 preflight). The runner reaches this pass only on
 * MySQL: UpgradeFromThreeCommand guards the driver before invoking the runner.
 */
final class EmojiTransform
{
    /**
     * Member-authored free-text columns only. Operator-authored configuration text (community
     * category names, mail templates, navigation labels, profile definitions / options /
     * translations, banners, sns settings) is left untouched: the carrier-emoji input path was the
     * mobile member UI, not the admin console.
     *
     * @var array<string, list<string>>
     */
    private const TABLES = [
        'members' => ['name'],
        'member_profiles' => ['value'],
        'diaries' => ['title', 'body'],
        'diary_comments' => ['body'],
        'groups' => ['name', 'description'],
        'group_topics' => ['name', 'body'],
        'group_topic_comments' => ['body'],
        'community_events' => ['name', 'body', 'open_date_comment', 'area'],
        'community_event_comments' => ['body'],
        'direct_messages' => ['subject', 'body'],
    ];

    // Matches one [i|e|s:NNN] code; narrows a chunk to rows carrying at least one. Double-escaped so
    // the MySQL string literal yields \[ ... \] for the regex engine (literal brackets, not a class).
    private const CODE_REGEXP = '\\\\[[ies]:[0-9]{1,3}\\\\]';

    // Bounds the per-transaction row count; progress is the id cursor, not a draining predicate.
    private const CHUNK = 500;

    public function plan(Closure $out): void
    {
        $out('PLAN would rewrite OpenPNE 3 carrier-emoji codes to Unicode across '.count(self::TABLES).' member-authored text tables.');
    }

    /** @param list<string> $targetTables this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, Closure $out): bool
    {
        if (! $this->assertUtf8mb4($out)) {
            return false;
        }

        foreach (self::TABLES as $table => $columns) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            $key = 'emoji_'.$table;

            if ($this->isCompleted($key)) {
                $out("SKIP {$key}: already completed");

                continue;
            }

            if (! $this->transformTable($table, $columns, $key, $out)) {
                return false;
            }
        }

        return true;
    }

    /**
     * utf8mb4 or abort: on a utf8mb3 connection MySQL mangles every non-BMP emoji to '?' as it writes
     * the converted value, silently corrupting the data this pass exists to repair.
     */
    private function assertUtf8mb4(Closure $out): bool
    {
        $charset = DB::selectOne('SELECT @@character_set_connection AS charset')->charset ?? '';

        if ($charset !== 'utf8mb4') {
            $out("FAIL emoji: connection charset is '{$charset}', expected utf8mb4 (non-BMP emoji would be lost).");

            return false;
        }

        return true;
    }

    /** @param list<string> $columns */
    private function transformTable(string $table, array $columns, string $key, Closure $out): bool
    {
        try {
            // Resume from a prior run's cursor; a fresh checkpoint starts at 0. The RUNNING write below
            // omits metadata, so this last_id survives it.
            $metadata = UpgradeState::query()->where('step_key', $key)->value('metadata');
            $cursor = is_array($metadata) ? (int) ($metadata['last_id'] ?? 0) : 0;

            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            $changed = 0;

            while (true) {
                $rows = DB::table($table)
                    ->select(array_merge(['id'], $columns))
                    ->where('id', '>', $cursor)
                    ->where(function ($query) use ($columns): void {
                        foreach ($columns as $column) {
                            $query->orWhereRaw("`{$column}` REGEXP '".self::CODE_REGEXP."'");
                        }
                    })
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $changed += DB::transaction(fn (): int => $this->convertChunk($table, $columns, $rows));

                // Advance past the chunk's last id even when nothing changed: an unmapped code survives
                // conversion, so rescanning the same rows would never terminate.
                $cursor = (int) $rows->last()->id;
                UpgradeState::updateOrCreate(['step_key' => $key], ['metadata' => ['last_id' => $cursor]]);
            }

            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_COMPLETED,
                'rows_affected' => $changed,
                'finished_at' => now(),
            ]);

            $out("DONE {$key}: {$changed} rows");

            return true;
        } catch (Throwable $e) {
            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out("FAIL {$key}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Convert every code-bearing column of each fetched row, writing only the columns that actually
     * changed — an all-unmapped row updates nothing, so its updated_at is left intact. Returns the
     * number of rows written.
     *
     * @param  list<string>  $columns
     * @param  Collection<int, object>  $rows
     */
    private function convertChunk(string $table, array $columns, Collection $rows): int
    {
        $changed = 0;

        foreach ($rows as $row) {
            $update = [];

            foreach ($columns as $column) {
                $value = $row->{$column};

                if ($value === null) {
                    continue;
                }

                $converted = EmojiMap::convert($value);

                if ($converted !== $value) {
                    $update[$column] = $converted;
                }
            }

            if ($update !== []) {
                DB::table($table)->where('id', $row->id)->update($update);
                $changed++;
            }
        }

        return $changed;
    }

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
