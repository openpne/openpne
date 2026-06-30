<?php

namespace App\Console\Commands;

use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates one OpenPNE 3 site's data into the OpenPNE 4 schema. The OpenPNE 3 source tables must
 * already be present on the app's connection (restored from a dump in the same database, or a
 * separate database on the same MySQL instance via --source-database). The target is always the
 * app's own database; the running app reads those tables.
 *
 * PR1 of the runner: the relational steps only. file_bin BLOB rewire and admin_user are follow-ups,
 * so a plain same-database cutover is not complete here yet (see the engine-only notice).
 */
class UpgradeFromThreeCommand extends Command
{
    protected $signature = 'openpne:upgrade-from-3
        {--source-prefix= : OpenPNE 3 table prefix (default empty)}
        {--source-database= : Database the OpenPNE 3 source was restored into (same MySQL instance)}
        {--dry-run : Print the planned SQL without writing anything}
        {--force-restart : Clear the upgrade state and target tables, then run from scratch}';

    protected $description = 'Migrate OpenPNE 3 data into the OpenPNE 4 schema (single site)';

    public function handle(): int
    {
        $options = $this->runOptions();

        if ($options === null) {
            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('openpne:upgrade-from-3 requires MySQL (the upgrade is a set-based INSERT...SELECT over the OpenPNE 3 DDL).');

            return self::FAILURE;
        }

        if (! Schema::hasTable('openpne4_upgrade_state')) {
            $this->error('The openpne4_upgrade_state table is missing — run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $runner = app(UpgradeRunner::class);
        $out = fn (string $line) => $this->line($line);

        if ($options->forceRestart && ! $options->dryRun) {
            $runner->reset($out);
        }

        // Shown on dry-run too, so planning a same-database cutover sees the engine-only caveat upfront.
        if ($options->sourcePrefix === '' && $options->sourceDatabase === null) {
            $this->warn('Runner engine: relational data migrates, but file_bin BLOB rewire and admin_user are not handled yet — this is not a complete same-database cutover.');
        }

        return $runner->run($options, $out) ? self::SUCCESS : self::FAILURE;
    }

    private function runOptions(): ?RunOptions
    {
        $prefix = (string) $this->option('source-prefix');
        $database = $this->option('source-database');

        // A non-empty prefix / database is interpolated into backticked SQL, so restrict it to a
        // table-name charset. Empty prefix / null database is the default same-database workflow.
        if ($prefix !== '' && ! preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            $this->error('--source-prefix must match [A-Za-z0-9_]+.');

            return null;
        }

        if ($database !== null && ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            $this->error('--source-database must match [A-Za-z0-9_]+.');

            return null;
        }

        return new RunOptions(
            sourcePrefix: $prefix,
            sourceDatabase: $database,
            dryRun: (bool) $this->option('dry-run'),
            forceRestart: (bool) $this->option('force-restart'),
        );
    }
}
