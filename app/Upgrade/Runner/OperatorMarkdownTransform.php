<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\OperatorContentConversion;
use App\Support\OperatorContentItem;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass writing the Markdown that stands in for the imported operator HTML
 * (docs/internals/upgrade.md, "Operator HTML"). The HTML itself stays as imported, so the pass can
 * run again without harm; the checkpoint only saves the work.
 */
final class OperatorMarkdownTransform implements Phase
{
    public const VERSION = '1';

    private const KEY = 'operator_markdown';

    public function plan(RunReport $report): void
    {
        $report->line('PLAN would write Markdown versions of the imported operator HTML (page inserts, footer, banners, information and free-area gadgets).');
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function checkpointKeys(array $targetTables, array $sourceTables): array
    {
        return $this->tables($targetTables) === [] ? [] : [self::KEY];
    }

    /** @param list<string> $targetTables this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, RunReport $report): bool
    {
        $tables = $this->tables($targetTables);

        if ($tables === []) {
            return true;
        }

        if (UpgradeState::query()->where('step_key', self::KEY)->where('status', UpgradeState::STATUS_COMPLETED)->exists()) {
            $report->skipped(self::KEY, 'already completed');

            return true;
        }

        $report->started(self::KEY);

        try {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            $items = DB::transaction(function () use ($tables): array {
                $items = (new OperatorContentConversion('run the upgrade again (shorten the imported copy in this database; a shortened OpenPNE 3 value needs --force-restart)'))->run($tables);

                UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => count(array_filter($items, static fn (OperatorContentItem $item): bool => $item->written)),
                    'finished_at' => now(),
                ]);

                return $items;
            });
        } catch (Throwable $e) {
            rescue(static fn () => UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => RunReport::failureMessage($e),
                'finished_at' => now(),
            ]));
            report($e);

            $report->failed(self::KEY, RunReport::failureMessage($e), $e);

            return false;
        }

        $written = count(array_filter($items, static fn (OperatorContentItem $item): bool => $item->written));
        $lossy = count(array_filter($items, static fn (OperatorContentItem $item): bool => $item->converted->isLossy()));

        $report->done(self::KEY, "{$written} values", $written);
        if ($lossy > 0) {
            $report->note("{$lossy} operator HTML values hold markup Markdown cannot express (scripts, frames, styles, colours) — list them with `php artisan openpne:convert-operator-html --dry-run`.");
        }

        return true;
    }

    /**
     * @param  list<string>  $targetTables
     * @return list<string>
     */
    private function tables(array $targetTables): array
    {
        return array_values(array_intersect(OperatorContentConversion::TABLES, $targetTables));
    }
}
