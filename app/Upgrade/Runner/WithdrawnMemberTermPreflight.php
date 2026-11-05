<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\WithdrawnMemberTermJaUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the sns_config value the WithdrawnMemberTermUpgrade INSERT would fail on mid-run: wider than
 * the term column (docs/internals/upgrade.md, "Source preflight").
 */
final class WithdrawnMemberTermPreflight
{
    /** The `value` column term_overrides declares. */
    private const VALUE_COLUMN_LENGTH = 255;

    /** @return list<string> errors; empty when the step can run */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase): array
    {
        $sql = sprintf(
            'SELECT MAX(CHAR_LENGTH(`value`)) AS `width` FROM %s AS `sns_config` WHERE %s',
            SourceRef::table('sns_config'),
            (new WithdrawnMemberTermJaUpgrade)->effectiveFilter(),
        );

        $rows = DB::select((new InsertSelectCompiler)->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase));
        $width = (int) ($rows[0]->width ?? 0);

        return $width > self::VALUE_COLUMN_LENGTH ? [self::tooLongMessage($width)] : [];
    }

    public static function tooLongMessage(int $width): string
    {
        return 'source `sns_config` value for `'.WithdrawnMemberTermUpgrade::SOURCE_NAME."` is {$width} characters; term_overrides.value holds ".self::VALUE_COLUMN_LENGTH.'. Shorten it in the source, then re-run.';
    }
}
