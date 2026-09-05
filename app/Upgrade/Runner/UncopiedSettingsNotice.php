<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use Illuminate\Support\Facades\DB;

/**
 * The OpenPNE 3 sns_config values that live in .env on OpenPNE 4, each reported with the value to set
 * so the operator carries it by hand. Reads the source table the structural preflight guards, so it
 * runs only after that verdict and only in a run whose steps read sns_config.
 */
final class UncopiedSettingsNotice
{
    public const ENV = 'OPENPNE_IMAGE_MAX_UPLOAD_KB';

    /** @return list<string> */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase): array
    {
        $rows = DB::select(
            'select `value` from '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'sns_config')
            .' where `name` = ? limit 1',
            ['image_max_filesize'],
        );

        if ($rows === []) {
            return [];
        }

        $value = (string) $rows[0]->value;
        $kilobytes = self::kilobytes($value);

        return [$kilobytes === null
            ? "sns_config image_max_filesize = {$value} is not copied and could not be read as a size; set ".self::ENV.' yourself (per file, kilobytes).'
            : "sns_config image_max_filesize = {$value} is not copied; to keep it, set ".self::ENV."={$kilobytes} in .env (per file, kilobytes).",
        ];
    }

    /** OpenPNE 3 opValidatorImageFile: a trailing K or M scales the integer prefix, anything else is bytes. */
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
