<?php

namespace App\Console\Commands;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\JsonOutput;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Runner\UpgradeStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * See docs/internals/upgrade.md, "Status".
 */
class UpgradeStatusCommand extends Command
{
    protected $signature = 'openpne:upgrade-status
        {--json : Emit the status as JSON instead of human-readable lines}';

    protected $description = 'Report where the OpenPNE 3 → 4 upgrade of this site stands';

    public function handle(): int
    {
        if (! Schema::hasTable('openpne4_upgrade_state')) {
            $this->error('The openpne4_upgrade_state table is missing — run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $status = UpgradeStatus::read(new UpgradeRunner(new InsertSelectCompiler));

        if ($this->option('json')) {
            JsonOutput::write($this, $status);
        } else {
            foreach ($status->lines() as $line) {
                $this->line(OutputFormatter::escape($line));
            }
        }

        return self::SUCCESS;
    }
}
