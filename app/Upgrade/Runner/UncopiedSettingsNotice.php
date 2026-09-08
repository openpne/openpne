<?php

namespace App\Upgrade\Runner;

use App\Support\SnsSettingKey;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * The sns_config rows the settings step leaves out on purpose, each reported with what applies
 * instead: the value that lives in .env here, and a NULL OpenPNE 3 read as its default. Reads a
 * source table the structural preflight guards, so it runs only after that verdict and only when a
 * step's source table is sns_config.
 */
final class UncopiedSettingsNotice
{
    public const ENV = 'OPENPNE_IMAGE_MAX_UPLOAD_KB';

    /** @return list<string> */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase): array
    {
        $table = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'sns_config');

        return array_merge($this->imageMaxFilesize($table), $this->nullValues($table));
    }

    /** @return list<string> */
    private function imageMaxFilesize(string $table): array
    {
        $rows = DB::select('select `value` from '.$table.' where `name` = ? limit 1', ['image_max_filesize']);

        // A NULL value went through opConfig::get in OpenPNE 3, so it read as the default, like no row.
        if ($rows === [] || $rows[0]->value === null) {
            return [];
        }

        $value = (string) $rows[0]->value;
        $kilobytes = self::kilobytes($value);

        return [$kilobytes === null
            ? "sns_config image_max_filesize = {$value} is not copied and could not be read as a size; set ".self::ENV.' yourself (per file, kilobytes).'
            : "sns_config image_max_filesize = {$value} is not copied; to keep it, set ".self::ENV."={$kilobytes} in .env (per file, kilobytes).",
        ];
    }

    /** @return list<string> */
    private function nullValues(string $table): array
    {
        $keys = (new SnsSettingUpgrade)->leftOutWhenNull();

        if ($keys === []) {
            return [];
        }

        $rows = DB::select(
            'select `name` from '.$table.' where `value` is null and `name` in ('.implode(', ', array_fill(0, count($keys), '?')).') order by `name`',
            array_keys($keys),
        );

        // The source collation is case-insensitive and PAD SPACE, so the IN matches a hand-edited spelling the
        // step copies under the same key; the lookup here has to match it the same way.
        $byLowerName = array_change_key_case($keys, CASE_LOWER);
        $notices = [];
        foreach ($rows as $row) {
            $key = $byLowerName[strtolower(rtrim($row->name, ' '))] ?? null;
            if ($key !== null) {
                $notices[] = self::nullValueMessage($row->name, $key);
            }
        }

        return $notices;
    }

    private static function nullValueMessage(string $name, SnsSettingKey $key): string
    {
        return sprintf(
            "sns_config `%s` is NULL and is not copied: OpenPNE 3 read its default there, and `%s` keeps its default here ('%s').",
            $name,
            $key->value,
            $key->encode($key->default()),
        );
    }

    /** OpenPNE 3 opValidatorImageFile scaled a trailing K or M and cast anything else to bytes; the other shapes are refused here rather than guessed. */
    public static function kilobytes(string $value): ?int
    {
        if (! preg_match('/^\s*(\d+)\s*([kKmM]?)\s*$/', $value, $m)) {
            return null;
        }

        $number = (int) $m[1];

        if ($number <= 0) {
            return null;
        }

        $bytes = match (strtoupper($m[2])) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            default => $number,
        };

        return (int) ceil($bytes / 1024);
    }
}
