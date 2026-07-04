<?php

namespace App\Upgrade\Runner;

use App\Auth\PasswordScheme;
use App\Models\UpgradeState;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Post-walk pass that removes bare OpenPNE 3 MD5 hashes at rest: every imported
 * 32-hex password becomes bcrypt(md5hex) with password_scheme = md5_bcrypt, so the
 * stored form is always a bcrypt string and login verifies flagged rows as
 * Hash::check(md5($attempt), $hash) until the first success rehashes to a plain
 * bcrypt (App\Actions\Fortify\AuthenticateMember / App\Auth\LegacyEloquentUserProvider).
 *
 * bcrypt cannot be computed in SQL, so this runs in PHP after the INSERT...SELECT
 * walk — the FileBinMigration precedent for work a step cannot express. The bare-MD5
 * predicate never matches a wrapped row, so the pass is idempotent and a failed run
 * resumes by simply rescanning; no progress cursor is needed. rows_affected on the
 * checkpoint counts rows wrapped by the completing run (a resume records the
 * remainder), which verify-upgrade does not consume — its invariant is the terminal
 * state, zero bare-MD5 rows.
 */
final class PasswordWrap
{
    private const KEYS = ['members' => 'password_wrap_members', 'admin_users' => 'password_wrap_admin_users'];

    private const BARE_MD5 = '^[0-9a-f]{32}$';

    // Each row costs a full bcrypt; the chunk bounds the per-transaction time, not memory.
    private const CHUNK = 200;

    // Wrap-only cost: 10 meets the password-storage floor while keeping a whole-fleet
    // import tractable (cost 12 would quadruple the CPU time); the first successful
    // login rehashes to the app default. Explicitly bcrypt — the scheme is named
    // md5_bcrypt, so a future default-hasher change must not alter what this produces.
    private const WRAP_COST = 10;

    public function plan(Closure $out): void
    {
        $out('PLAN would wrap imported bare-MD5 passwords as bcrypt(md5) with password_scheme=md5_bcrypt (members, admin_users).');
    }

    /** @param list<string> $targetTables this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, Closure $out): bool
    {
        foreach (self::KEYS as $table => $key) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            if ($this->isCompleted($key)) {
                $out("SKIP {$key}: already completed");

                continue;
            }

            if (! $this->wrapTable($table, $key, $out)) {
                return false;
            }
        }

        return true;
    }

    private function wrapTable(string $table, string $key, Closure $out): bool
    {
        try {
            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            $wrapped = 0;

            while (true) {
                $rows = DB::table($table)
                    ->select(['id', 'password'])
                    ->whereRaw("`password` REGEXP '".self::BARE_MD5."'")
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                DB::transaction(function () use ($table, $rows): void {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            'password' => Hash::driver('bcrypt')->make($row->password, ['rounds' => self::WRAP_COST]),
                            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
                        ]);
                    }
                });

                $wrapped += $rows->count();
                $out("WRAP {$key}: {$wrapped} rows so far");
            }

            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_COMPLETED,
                'rows_affected' => $wrapped,
                'finished_at' => now(),
            ]);

            $out("DONE {$key}: {$wrapped} rows");

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

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
