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

            if ($step->gaps() !== []) {
                $this->line('');
                $this->line('Accepted gaps:');
                foreach ($step->gaps() as $name => $reason) {
                    $this->line("- `{$name}` — {$reason}");
                }
            }

            $this->line('');
        }

        return self::SUCCESS;
    }
}
