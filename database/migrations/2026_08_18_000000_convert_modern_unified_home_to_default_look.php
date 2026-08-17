<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The `modern_unified_home` bool becomes `default_look`, a look id: a site that had the experiment
 * on renders `unified`, every other one `standard` — the absent-row default, so only the ON side
 * writes a row. Key names are literals here because the retired one no longer exists as an enum
 * case, and a migration must keep meaning what it meant on the day it ran.
 *
 * Every other `sns_settings` writer goes through SnsSettingService::clearCache(); a migration does
 * not, so it drops the core tier itself — a warm map would keep serving the retired key, and the
 * site would sit on `standard` for the rest of the hour after deploy.
 */
return new class extends Migration
{
    private const OLD_KEY = 'modern_unified_home';

    private const NEW_KEY = 'default_look';

    private const CORE_CACHE_KEY = 'sns_settings';

    public function up(): void
    {
        if (DB::table('sns_settings')->where('key', self::OLD_KEY)->value('value') === '1') {
            DB::table('sns_settings')->updateOrInsert(['key' => self::NEW_KEY], ['value' => 'unified']);
        }

        DB::table('sns_settings')->where('key', self::OLD_KEY)->delete();

        Cache::forget(self::CORE_CACHE_KEY);
    }

    public function down(): void
    {
        // Only `unified` maps back to the old switch's ON side: a look registered later is not the
        // experiment this converted, and must not be promoted to it.
        if (DB::table('sns_settings')->where('key', self::NEW_KEY)->value('value') === 'unified') {
            DB::table('sns_settings')->updateOrInsert(['key' => self::OLD_KEY], ['value' => '1']);
        }

        DB::table('sns_settings')->where('key', self::NEW_KEY)->delete();

        Cache::forget(self::CORE_CACHE_KEY);
    }
};
