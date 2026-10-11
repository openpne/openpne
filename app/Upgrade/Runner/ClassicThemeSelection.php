<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\ClassicThemes;
use App\Support\SnsSettingKey;
use App\Upgrade\InsertSelectCompiler;
use Closure;
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

    public function plan(Closure $out): void
    {
        $out('PLAN would set classic_theme from the OpenPNE 3 skin plugins and Theme_used.');
    }

    /** @param  list<string>  $targetTables  this run's step target tables (skip unless the run owns sns_settings) */
    public function run(array $targetTables, string $sourcePrefix, ?string $sourceDatabase, Closure $out): bool
    {
        if (! in_array('sns_settings', $targetTables, true)) {
            return true;
        }

        if (UpgradeState::query()->where('step_key', self::KEY)->where('status', UpgradeState::STATUS_COMPLETED)->exists()) {
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

            $theme = $this->select($sourcePrefix, $sourceDatabase);

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
            UpgradeState::updateOrCreate(['step_key' => self::KEY], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out('FAIL '.self::KEY.": {$e->getMessage()}");

            return false;
        }

        $out('DONE '.self::KEY.': classic_theme = '.($theme === '' ? 'the default skin' : "'{$theme}'"));

        return true;
    }

    /**
     * OpenPNE 3's own rule read off its tables: a skin plugin with no `plugin` row counts as enabled,
     * only a theme plugin that is the one enabled skin dressed the site, and then `Theme_used` named
     * the package or the first one in directory order stood in.
     */
    public function select(string $sourcePrefix, ?string $sourceDatabase): string
    {
        $enabled = [self::THEME_PLUGIN => true, self::BASIC_PLUGIN => true];
        $plugin = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'plugin');
        foreach (DB::select('select `name`, `is_enabled` from '.$plugin.' where `name` in (?, ?)', [self::THEME_PLUGIN, self::BASIC_PLUGIN]) as $row) {
            $enabled[$row->name] = (bool) $row->is_enabled;
        }

        if (! $enabled[self::THEME_PLUGIN] || $enabled[self::BASIC_PLUGIN]) {
            return '';
        }

        $config = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'sns_config');
        $rows = DB::select('select `value` from '.$config.' where `name` = ? limit 1', ['Theme_used']);
        $used = $rows === [] ? null : $rows[0]->value;

        if (is_string($used) && ClassicThemes::find($used) !== null) {
            return $used;
        }

        return (string) (array_key_first(ClassicThemes::all()) ?? '');
    }
}
