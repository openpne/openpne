<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Post-walk pass rewriting the imported site-policy bodies as Markdown (Op3PolicyMarkdown), since an
 * INSERT...SELECT cannot reformat text (docs/internals/upgrade.md, "Post-walk passes"). Not
 * idempotent — escaping an escaped body doubles its backslashes — so the completed checkpoint is
 * what makes a resume safe, and both rows are rewritten under one checkpoint in one transaction.
 */
final class SitePolicyMarkdownTransform implements Phase
{
    public const VERSION = '1';

    private const KEY = 'site_policy_markdown';

    private const TABLE = 'sns_settings';

    /** @var list<SnsSettingKey> */
    private const SETTINGS = [SnsSettingKey::UserAgreement, SnsSettingKey::PrivacyPolicy];

    public function plan(RunReport $report): void
    {
        $report->line('PLAN would rewrite the imported terms of service / privacy policy bodies as Markdown.');
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function checkpointKeys(array $targetTables, array $sourceTables): array
    {
        return in_array(self::TABLE, $targetTables, true) ? [self::KEY] : [];
    }

    /** @param list<string> $targetTables this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, RunReport $report): bool
    {
        if (! in_array(self::TABLE, $targetTables, true)) {
            return true;
        }

        if ($this->isCompleted(self::KEY)) {
            $report->skipped(self::KEY, 'already completed');

            return true;
        }

        // The progress lines stay outside the try: a throwing output closure would otherwise flip a
        // committed COMPLETED to FAILED, and the resume would rewrite the already-converted bodies.
        $report->started(self::KEY);

        try {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            // The rewrite and its COMPLETED checkpoint commit together, so a crash cannot leave
            // converted rows without a checkpoint for the non-idempotent resume to convert again.
            $converted = DB::transaction(function (): int {
                $converted = $this->rewrite();

                UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => $converted,
                    'finished_at' => now(),
                ]);

                return $converted;
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

        $report->done(self::KEY, "{$converted} rows", $converted);
        if ($converted > 0) {
            // Best-effort: OpenPNE 3 accepted markup this cannot always place (indented blocks,
            // hand-drawn tables), so the operator is told to read the result once.
            $report->note('site policy text was rewritten as Markdown — review it under Settings > Site policy settings.');
        }

        return true;
    }

    /** @return int rows whose body changed */
    private function rewrite(): int
    {
        $converted = 0;

        foreach (self::SETTINGS as $setting) {
            $value = DB::table(self::TABLE)->where('key', $setting->value)->value('value');
            if (! is_string($value)) {
                continue;
            }

            $markdown = Op3PolicyMarkdown::convert($value);
            if ($markdown === $value) {
                continue;
            }

            // Escaping lengthens the text, so a body that fit OpenPNE 3's TEXT column can outgrow the
            // target; failing (and rolling the pass back) beats letting the column truncate a legal document.
            $bytes = strlen($markdown);
            if ($bytes > $setting->maxBytes()) {
                // The walk has already completed, so it will not re-copy a shortened OpenPNE 3 value:
                // recovery is either editing the imported text down in the admin, or shortening the
                // source and re-importing from scratch.
                throw new RuntimeException(
                    "{$setting->value}: the Markdown rewrite is {$bytes} bytes, over the {$setting->maxBytes()} the column holds — shorten it under Settings > Site policy settings (or shorten the OpenPNE 3 value and re-run with --force-restart), then run the upgrade again"
                );
            }

            DB::table(self::TABLE)->where('key', $setting->value)->update(['value' => $markdown]);
            $converted++;
        }

        return $converted;
    }

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
