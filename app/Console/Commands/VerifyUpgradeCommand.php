<?php

namespace App\Console\Commands;

use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Verify\UpgradeVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * A gate rather than a report: any failed check fails the command.
 *
 * It runs against the same source the runner used, so pass the same --source-prefix /
 * --source-database.
 */
class VerifyUpgradeCommand extends Command
{
    protected $signature = 'openpne:verify-upgrade
        {--source-prefix= : OpenPNE 3 table prefix (default empty)}
        {--source-database= : Database the OpenPNE 3 source was restored into (same MySQL instance)}
        {--json : Emit the report as JSON instead of human-readable lines}';

    protected $description = 'Verify an OpenPNE 3 → 4 migration (row-count parity + file_bin integrity)';

    public function handle(): int
    {
        $options = $this->verifyOptions();
        if ($options === null) {
            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('openpne:verify-upgrade requires MySQL (it reads the OpenPNE 3 source tables and LENGTH(bin)).');

            return self::FAILURE;
        }

        if (! Schema::hasTable('openpne4_upgrade_state') || ! Schema::hasTable('files')) {
            $this->error('The OpenPNE 4 schema is not migrated (openpne4_upgrade_state / files missing) — run the upgrade first.');

            return self::FAILURE;
        }

        $json = (bool) $this->option('json');
        $report = app(UpgradeVerifier::class)->verify($options, $json ? null : fn (string $line) => $this->line(OutputFormatter::escape($line)));

        if ($json) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->line('');
            $this->line("{$report->passedCount()}/".count($report->checks()).' checks passed.');
        }

        return $report->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function verifyOptions(): ?RunOptions
    {
        $prefix = (string) ($this->option('source-prefix') ?? '');
        $database = $this->option('source-database');
        $database = $database === null ? null : (string) $database;

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

        return new RunOptions(sourcePrefix: $prefix, sourceDatabase: $database);
    }
}
