<?php

namespace App\Console\Commands;

use App\Upgrade\Runner\JsonOutput;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\RunReport;
use App\Upgrade\Runner\UpgradeRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * See docs/internals/upgrade.md; the operator guide is docs/upgrading-from-openpne3.md.
 */
class UpgradeFromThreeCommand extends Command
{
    protected $signature = 'openpne:upgrade-from-3
        {--source-prefix= : OpenPNE 3 table prefix (default empty)}
        {--source-database= : Database the OpenPNE 3 source was restored into (same MySQL instance)}
        {--dry-run : Print the planned SQL without writing anything}
        {--force-restart : Clear the upgrade state and target tables, then run from scratch — the way out when a resume is refused (another naming epoch, or a different set of steps)}
        {--json : Emit the run report as JSON instead of human-readable lines}';

    protected $description = 'Migrate OpenPNE 3 data into the OpenPNE 4 schema (single site)';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        // Source-derived text (setting values, unknown names) rides in these lines, and an unescaped
        // `<fg=…>` in it would make the console formatter throw.
        $sink = $json ? null : fn (string $line) => $this->line(OutputFormatter::escape($line));
        $report = new RunReport($sink, $this->output->isVerbose(), (bool) $this->option('dry-run'));

        // The report is printed on every exit, the guards and a throwing one included, whatever the
        // verbosity; under -v the exception follows it on stdout.
        $rethrow = null;
        try {
            $ok = $this->upgrade($report);
        } catch (Throwable $e) {
            // Reported once: a failure a step recorded is already in the log, and the Kernel logs a rethrow.
            if (! $report->isFailed()) {
                report($e);
                $report->failed('run', RunReport::failureMessage($e));
            }
            $report->finish(false);
            $ok = false;
            $rethrow = $this->output->isVerbose() ? $e : null;
        }

        if ($json) {
            JsonOutput::write($this, $report);
        }

        if ($rethrow !== null) {
            throw $rethrow;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function upgrade(RunReport $report): bool
    {
        $options = $this->runOptions($report);

        if ($options === null) {
            return false;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return $this->precheckFailed($report, 'openpne:upgrade-from-3 requires MySQL (the upgrade is a set-based INSERT...SELECT over the OpenPNE 3 DDL).');
        }

        if (! Schema::hasTable('openpne4_upgrade_state')) {
            return $this->precheckFailed($report, 'The openpne4_upgrade_state table is missing — run `php artisan migrate` first.');
        }

        // OpenPNE 3 DATETIMEs are copied through unchanged, so APP_TIMEZONE must already name the zone
        // the source ran in (docs/internals/runtime.md, "Upgrading from OpenPNE 3").
        $report->line('Site timezone: '.config('app.timezone').' — migrated timestamps are read as this zone, unconverted.');

        // --force-restart is applied inside run(), only after the source preflight passes, so a bad
        // source cannot delete existing target rows before aborting.
        return app(UpgradeRunner::class)->run($options, null, $report);
    }

    private function precheckFailed(RunReport $report, string $message): bool
    {
        $report->error($message);
        $report->abort('nothing was run.');
        $report->finish(false);

        return false;
    }

    private function runOptions(RunReport $report): ?RunOptions
    {
        $prefix = (string) $this->option('source-prefix');
        $database = $this->option('source-database');

        // A non-empty prefix or database is interpolated inside backticks, so restrict it to a charset that cannot close them.
        if ($prefix !== '' && ! preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            $this->precheckFailed($report, '--source-prefix must match [A-Za-z0-9_]+.');

            return null;
        }

        if ($database !== null && ! preg_match('/^[A-Za-z0-9_-]+$/', $database)) {
            $this->precheckFailed($report, '--source-database must match [A-Za-z0-9_-]+.');

            return null;
        }

        return new RunOptions(
            sourcePrefix: $prefix,
            sourceDatabase: $database,
            dryRun: (bool) $this->option('dry-run'),
            forceRestart: (bool) $this->option('force-restart'),
            verbose: $this->output->isVerbose(),
        );
    }
}
