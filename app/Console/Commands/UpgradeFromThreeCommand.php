<?php

namespace App\Console\Commands;

use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * See docs/internals/upgrade.md; the operator guide is docs/upgrading-from-openpne3.md.
 */
class UpgradeFromThreeCommand extends Command
{
    protected $signature = 'openpne:upgrade-from-3
        {--source-prefix= : OpenPNE 3 table prefix (default empty)}
        {--source-database= : Database the OpenPNE 3 source was restored into (same MySQL instance)}
        {--dry-run : Print the planned SQL without writing anything}
        {--force-restart : Clear the upgrade state and target tables, then run from scratch — required to resume after a step definition changed, since checkpoints do not record it}';

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

        // OpenPNE 3 DATETIMEs are copied through unchanged, so APP_TIMEZONE must already name the zone
        // the source ran in (docs/internals/runtime.md, "Upgrading from OpenPNE 3").
        $this->line('Site timezone: '.config('app.timezone').' — migrated timestamps are read as this zone, unconverted.');

        $runner = app(UpgradeRunner::class);
        // Source-derived text (setting values, unknown names) rides in these lines, and an unescaped
        // `<fg=…>` in it would make the console formatter throw.
        $out = fn (string $line) => $this->line(OutputFormatter::escape($line));

        // --force-restart is applied inside run(), only after the source preflight passes, so a bad
        // source cannot delete existing target rows before aborting.

        return $runner->run($options, $out) ? self::SUCCESS : self::FAILURE;
    }

    private function runOptions(): ?RunOptions
    {
        $prefix = (string) $this->option('source-prefix');
        $database = $this->option('source-database');

        // A non-empty prefix or database is interpolated into backticked SQL, so restrict it to a
        // table-name charset.
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
