<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceLocale;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `sns_term` + `sns_term_translation` → OpenPNE 4 `term_overrides`, name for name and value
 * for value, so a migrated site keeps the wording its members know even where OpenPNE 4 ships a new
 * default (docs/internals/upgrade.md, "Terms"). Only the PC application's rows: the mobile ones hold
 * half-width kana for feature phones.
 */
class TermOverrideUpgrade extends UpgradeStep
{
    public const APPLICATION = 'pc_frontend';

    /**
     * The stock OpenPNE 3 names that fill the same slot in OpenPNE 4, each a `lang/{locale}/terms.php`
     * key; `diary` / `topic` are OpenPNE 4 additions with no source row.
     */
    public const SOURCE_NAMES = ['friend', 'my_friend', 'community', 'nickname', 'activity'];

    /**
     * Stock names recognised but not carried: OpenPNE 3 rendered `post_activity` only as the posting
     * button's verb (つぶやく), OpenPNE 4 fills a noun slot with it.
     */
    public const UNCARRIED_NAMES = ['post_activity'];

    protected string $source = 'sns_term_translation';

    protected string $target = 'term_overrides';

    public function columns(): array
    {
        return [
            'name' => Column::expr(self::nameSelect(), uses: ['id']),
            'locale' => Column::expr(SourceLocale::foldExpr(), uses: ['lang']),
            'value' => Column::source('value'),
        ];
    }

    public function filter(): ?string
    {
        return '`value` IS NOT NULL AND `id` IN ('.self::termIdSelect().')';
    }

    public function filterColumns(): array
    {
        return ['value', 'id'];
    }

    /** The OpenPNE 3 term name of the translation row, correlated on the FROM alias. */
    public static function nameSelect(): string
    {
        return '(SELECT `t`.`name` FROM '.SourceRef::table('sns_term').' `t` WHERE `t`.`id` = `sns_term_translation`.`id`)';
    }

    /** The `sns_term` ids the step carries: the PC application's rows with a recognised name. */
    public static function termIdSelect(): string
    {
        return sprintf(
            'SELECT `id` FROM %s WHERE `application` = \'%s\' AND `name` IN (%s)',
            SourceRef::table('sns_term'),
            self::APPLICATION,
            implode(', ', array_map(static fn (string $name): string => "'{$name}'", self::SOURCE_NAMES)),
        );
    }
}
