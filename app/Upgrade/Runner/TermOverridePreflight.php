<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceLocale;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\TermOverrideUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the source term rows the TermOverrideUpgrade INSERT would fail on mid-run: two rows folding
 * onto one (name, locale) key, or a value wider than the column (docs/internals/upgrade.md, "Source
 * preflight").
 */
final class TermOverridePreflight
{
    /** The `value` column term_overrides declares. */
    private const VALUE_COLUMN_LENGTH = 255;

    /** @return list<string> errors; empty when the step can run */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase): array
    {
        $errors = [];

        foreach ($this->rows($sourcePrefix, $sourceDatabase) as $row) {
            if ((int) $row->rows > 1) {
                $errors[] = self::collisionMessage((string) $row->name, (string) $row->locale, (int) $row->rows);
            }
            if ((int) $row->width > self::VALUE_COLUMN_LENGTH) {
                $errors[] = self::tooLongMessage((string) $row->name, (string) $row->locale, (int) $row->width);
            }
        }

        return $errors;
    }

    /** @return list<object{name: string, locale: string, rows: int|string, width: int|string}> */
    private function rows(string $sourcePrefix, ?string $sourceDatabase): array
    {
        $sql = sprintf(
            'SELECT %s AS `name`, %s AS `locale`, COUNT(*) AS `rows`, MAX(CHAR_LENGTH(`value`)) AS `width`'
            .' FROM %s AS `sns_term_translation` WHERE %s GROUP BY `name`, `locale` ORDER BY `name`, `locale`',
            TermOverrideUpgrade::nameSelect(),
            SourceLocale::foldExpr(),
            SourceRef::table('sns_term_translation'),
            (new TermOverrideUpgrade)->effectiveFilter(),
        );

        return DB::select((new InsertSelectCompiler)->resolveSourceRefs($sql, $sourcePrefix, $sourceDatabase));
    }

    public static function collisionMessage(string $name, string $locale, int $rows): string
    {
        return "source `sns_term` holds {$rows} `".TermOverrideUpgrade::APPLICATION."` rows named `{$name}` whose lang folds to `{$locale}`; term_overrides keeps one value per (name, locale), so the upgrade will not pick one. Delete the duplicates in the source, then re-run.";
    }

    public static function tooLongMessage(string $name, string $locale, int $width): string
    {
        return "source `sns_term` value for `{$name}` (`{$locale}`) is {$width} characters; term_overrides.value holds ".self::VALUE_COLUMN_LENGTH.'. Shorten it in the source, then re-run.';
    }
}
