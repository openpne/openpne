<?php

namespace App\Upgrade\Verify;

use App\Support\ClassicThemes;
use App\Support\SnsSettingKey;
use Closure;
use Illuminate\Support\Facades\DB;

/** The stored `classic_theme` names a package on disk or the default skin; no checkpoint is consulted. */
final class ClassicThemeCheck
{
    /**
     * @param  list<string>  $targetTables
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(array $targetTables, Closure $record): void
    {
        if (! in_array('sns_settings', $targetTables, true)) {
            return;
        }

        $value = (string) (DB::table('sns_settings')->where('key', SnsSettingKey::ClassicTheme->value)->value('value') ?? '');

        if ($value === '') {
            $record('classic_theme', true, 'the default skin');

            return;
        }

        $found = ClassicThemes::find($value) !== null;
        $record('classic_theme', $found, $found ? "theme package '{$value}' is on disk" : "theme package '{$value}' is not under public/opSkinThemePlugin");
    }
}
