<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\SnsSettingKey;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Post-walk pass that rewrites the imported site-policy bodies as Markdown (Op3PolicyMarkdown).
 * The walk copies `sns_config.value` verbatim because an INSERT...SELECT cannot reformat text; this
 * runs right after it, like PasswordWrap and EmojiTransform.
 *
 * Unlike those two the rewrite is NOT idempotent — escaping an already-escaped body doubles its
 * backslashes — so the completed checkpoint is what makes a resume safe, not a predicate that
 * drains. Both rows are rewritten in one transaction under one checkpoint: a partial pass would
 * otherwise leave no record of which of the two was already converted.
 */
final class SitePolicyMarkdownTransform
{
    private const KEY = 'site_policy_markdown';

    private const TABLE = 'sns_settings';

    /** @var list<SnsSettingKey> */
    private const SETTINGS = [SnsSettingKey::UserAgreement, SnsSettingKey::PrivacyPolicy];

    public function plan(Closure $out): void
    {
        $out('PLAN would rewrite the imported terms of service / privacy policy bodies as Markdown.');
    }

    /** @param list<string> $targetTables this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, Closure $out): bool
    {
        if (! in_array(self::TABLE, $targetTables, true)) {
            return true;
        }

        if ($this->isCompleted(self::KEY)) {
            $out('SKIP '.self::KEY.': already completed');

            return true;
        }

        try {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            // The rewrite and its COMPLETED checkpoint commit together: completed if and only if the
            // converted bodies landed. Marking completion in a later statement would let a crash in
            // between leave converted rows with no checkpoint, and the resume — this pass is not
            // idempotent — would escape the escapes a second time.
            $converted = DB::transaction(function (): int {
                $converted = $this->rewrite();

                UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => $converted,
                    'finished_at' => now(),
                ]);

                return $converted;
            });

            $out('DONE '.self::KEY.": {$converted} rows");
            if ($converted > 0) {
                // Best-effort: OpenPNE 3 accepted markup this cannot always place (indented blocks,
                // hand-drawn tables), so the operator is told to read the result once.
                $out('NOTE site policy text was rewritten as Markdown — review it under Settings > Site policy settings.');
            }

            return true;
        } catch (Throwable $e) {
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out('FAIL '.self::KEY.": {$e->getMessage()}");

            return false;
        }
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

            // Escapes and Markdown syntax make the text longer, so a body that just fit OpenPNE 3's
            // TEXT column can outgrow OpenPNE 4's. Fail with the key and the size rather than let the
            // column truncate a legal document mid-sentence; the whole pass rolls back with it.
            $bytes = strlen($markdown);
            if ($bytes > $setting->maxBytes()) {
                throw new RuntimeException(
                    "{$setting->value}: the Markdown rewrite is {$bytes} bytes, over the {$setting->maxBytes()} the column holds — shorten the OpenPNE 3 text and run again"
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
