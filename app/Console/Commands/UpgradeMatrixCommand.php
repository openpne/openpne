<?php

namespace App\Console\Commands;

use App\Upgrade\StepRegistry;
use Illuminate\Console\Command;

/** Renders the OpenPNE 3 → 4 upgrade mapping matrix (all steps) as Markdown. */
class UpgradeMatrixCommand extends Command
{
    protected $signature = 'openpne:upgrade-matrix';

    protected $description = 'Render the OpenPNE 3 → 4 upgrade mapping matrix as Markdown';

    public function handle(): int
    {
        foreach (StepRegistry::all() as $step) {
            $this->line("## `{$step->sourceTable()}` → `{$step->targetTable()}`");
            $this->line('');
            $this->line('| target column | source / expression |');
            $this->line('|---|---|');

            foreach ($step->columns() as $target => $column) {
                $from = $column->source ?? '`'.str_replace("\n", ' ', (string) $column->expr).'`';
                $this->line("| `{$target}` | {$from} |");
            }

            if ($step->filter() !== null) {
                // Without this the matrix reads as a full-table copy; the filter is what
                // splits one source table across several targets.
                $this->line('');
                $this->line("Filter: `{$step->filter()}`");
            }

            if ($step->pendingTargets() !== []) {
                $this->line('');
                $this->line('Pending targets:');
                foreach ($step->pendingTargets() as $name => $reason) {
                    $this->line("- `{$name}` — {$reason}");
                }
            }

            if ($step->gaps() !== []) {
                $this->line('');
                $this->line('Accepted gaps:');
                foreach ($step->gaps() as $name => $reason) {
                    $this->line("- `{$name}` — {$reason}");
                }
            }

            $this->line('');
        }

        if (StepRegistry::deferredSourceTables() !== []) {
            $this->line('## Deferred source tables');
            $this->line('');
            $this->line('OpenPNE 3 source tables with an OpenPNE 4 successor but no upgrade step yet:');
            foreach (StepRegistry::deferredSourceTables() as $table => $reason) {
                $this->line("- `{$table}` — {$reason}");
            }
            $this->line('');
        }

        return self::SUCCESS;
    }
}
