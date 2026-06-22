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
            $this->line('## Deferred / flattened source tables');
            $this->line('');
            $this->line('OpenPNE 3 source tables not driven by a standalone step — either deferred (a successor table exists but no step yet) or flattened into another table via subquery:');
            foreach (StepRegistry::deferredSourceTables() as $table => $reason) {
                $this->line("- `{$table}` — {$reason}");
            }
            $this->line('');
        }

        if (StepRegistry::unownedFileColumns() !== []) {
            $this->line('## Migrated columns whose file is left ownerless');
            $this->line('');
            $this->line('file_id columns on migrated tables whose file FileUpgrade does not assign an owner yet (the binary and the link are kept; the owner is backfilled when the feature lands):');
            foreach (StepRegistry::unownedFileColumns() as $column => $reason) {
                $this->line("- `{$column}` — {$reason}");
            }
            $this->line('');
        }

        // member_config is a KV table; the per-step column audit cannot show which names are
        // migrated vs dropped, so list that per-name coverage explicitly.
        $this->line('## `member_config` name coverage');
        $this->line('');
        $this->line('Per-name disposition of OpenPNE 3 `member_config`. A name not listed is an unrecognised custom config the upgrade does not migrate.');
        foreach (StepRegistry::memberConfigDispositions() as $name => $disposition) {
            $this->line("- `{$name}` — {$disposition}");
        }
        $this->line('');

        // community_config is the same shape: a KV table read by subquery, so its per-name coverage
        // is listed rather than derived from a step.
        $this->line('## `community_config` name coverage');
        $this->line('');
        $this->line('Per-name disposition of OpenPNE 3 `community_config`. A name not listed is an unrecognised custom config the upgrade does not migrate.');
        foreach (StepRegistry::communityConfigDispositions() as $name => $disposition) {
            $this->line("- `{$name}` — {$disposition}");
        }
        $this->line('');

        return self::SUCCESS;
    }
}
