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
 * The relational steps plus file_bin (the BLOBs) and admin_user. file_bin: in-place (same database, no
 * prefix) rewires its FK onto `files`; a --source-prefix / --source-database run instead RENAMEs the
 * source file_bin onto the app's (source-destructive — needs a disposable dump and DROP/RENAME rights
 * on the source). Admin accounts migrate with their OpenPNE 3 password hash; until the admin guard's
 * legacy-hash login lands, a migrated administrator must reset via `openpne:admin:reset-password`.
 *
 * A source preflight runs first: a recognized optional source table (an uninstalled OpenPNE 3 plugin)
 * is created empty and its step is skipped, but a missing required table/column aborts — upgrade the
 * OpenPNE 3 source to a supported version (core >= 3.6.x) first, or use --source-database (a separate
 * database) for a customised source whose tables would clash with OpenPNE 4's.
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

        // --force-restart is applied inside run(), only after the source preflight passes, so a bad
        // source cannot delete existing target rows before aborting.

        // Every workflow migrates admin_user, but the admin guard's legacy-hash login is not in yet, so
        // a migrated administrator's OpenPNE 3 password does not work until reset. Unconditional caveat.
        $this->warn('Migrated administrators must reset their password (`openpne:admin:reset-password`) until the admin legacy-hash login lands.');

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
