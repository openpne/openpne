<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\ClassicThemes;
use App\Support\SnsSettingKey;
use App\Upgrade\InsertSelectCompiler;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post-walk pass writing `classic_theme` from the skin OpenPNE 3 showed (docs/internals/upgrade.md,
 * "Post-walk passes"): OpenPNE 3 chose the skin by plugin activation, which `Theme_used` alone does
 * not record, and a site with no `Theme_used` row still wore the first theme package.
 */
final class ClassicThemeSelection
{
    public const KEY = 'classic_theme_selection';

    private const THEME_PLUGIN = 'opSkinThemePlugin';

    private const BASIC_PLUGIN = 'opSkinBasicPlugin';

    public static function unknownPackageMessage(): string
    {
        return self::KEY.': OpenPNE 3 dressed this site in the first theme package on its disk, which this run cannot see; the default skin is set until you copy that package under public/opSkinThemePlugin and choose it on Appearance (Classic) > Theme.';
    }

    public static function missingPackageMessage(string $theme): string
    {
        return self::KEY.": Theme_used '{$theme}' is not under public/opSkinThemePlugin, so the default skin shows until the package is copied there (verify-upgrade fails until then); or choose another on Appearance (Classic) > Theme.";
    }

    public function plan(RunReport $report): void
    {
        $report->line('PLAN would set classic_theme from the OpenPNE 3 skin plugins and Theme_used.');
    }

    /** @param  list<string>  $targetTables  this run's step target tables (skip unless the run owns sns_settings) */
    public function run(array $targetTables, string $sourcePrefix, ?string $sourceDatabase, RunReport $report): bool
    {
        if (! in_array('sns_settings', $targetTables, true)) {
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

            $selected = $this->select($sourcePrefix, $sourceDatabase);
            $theme = $selected ?? '';

            DB::transaction(function () use ($theme): void {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => SnsSettingKey::ClassicTheme->value],
                    ['value' => $theme],
                );

                UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => 1,
                    'finished_at' => now(),
                ]);
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

        $report->done(self::KEY, 'classic_theme = '.($theme === '' ? 'the default skin' : "'{$theme}'"));
        if ($selected === null) {
            $report->warning(self::unknownPackageMessage());
        } elseif ($theme !== '' && ClassicThemes::find($theme) === null) {
            $report->warning(self::missingPackageMessage($theme));
        }

        return true;
    }

    /**
     * OpenPNE 3's rule as far as its tables show it: the two bundled skins count as enabled without a
     * `plugin` row, and only the theme plugin as the one enabled `opSkin*` plugin dressed the site.
     * `Theme_used` is kept as written, even unvendored; null when absent or empty, since OpenPNE 3 then wore the first package on its disk.
     */
    public function select(string $sourcePrefix, ?string $sourceDatabase): ?string
    {
        $enabled = [self::THEME_PLUGIN => true, self::BASIC_PLUGIN => true];
        $plugin = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'plugin');
        foreach (DB::select('select `name`, `is_enabled` from '.$plugin.' where `name` like ?', ['opSkin%']) as $row) {
            $enabled[$row->name] = (bool) $row->is_enabled;
        }

        if (array_keys(array_filter($enabled)) !== [self::THEME_PLUGIN]) {
            return '';
        }

        $config = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'sns_config');
        $rows = DB::select('select `value` from '.$config.' where `name` = ? limit 1', ['Theme_used']);
        $used = $rows === [] ? null : $rows[0]->value;

        if (is_string($used) && $used !== '') {
            return $used;
        }

        return null;
    }
}
