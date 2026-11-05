<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\TermValue;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `sns_config.nickname_of_member_who_does_not_have_credentials` → the OpenPNE 4
 * `withdrawn_member` term, one subclass per locale because OpenPNE 3 showed the one value to every
 * reader (docs/internals/upgrade.md, "Terms"). The value is carried as the admin page would save it
 * (TermValue), and the OpenPNE 3 default `-` is not copied: OpenPNE 4 keeps its own default there.
 */
abstract class WithdrawnMemberTermUpgrade extends UpgradeStep
{
    public const SOURCE_NAME = 'nickname_of_member_who_does_not_have_credentials';

    public const SOURCE_DEFAULT = '-';

    public const TERM = 'withdrawn_member';

    protected string $source = 'sns_config';

    protected string $target = 'term_overrides';

    abstract public function locale(): string;

    public function columns(): array
    {
        return [
            'name' => Column::expr(sprintf("'%s'", self::TERM)),
            'locale' => Column::expr(sprintf("'%s'", $this->locale())),
            'value' => Column::expr(TermValue::trimmedExpr('`value`'), uses: ['value']),
        ];
    }

    public function filter(): ?string
    {
        // BINARY: the column's collation reads a full-width dash as the default.
        return sprintf(
            "`name` = '%s' AND `value` IS NOT NULL AND %s AND %s <> BINARY '%s'",
            self::SOURCE_NAME,
            TermValue::carriedExpr('`value`'),
            TermValue::trimmedExpr('`value`'),
            self::SOURCE_DEFAULT,
        );
    }

    public function filterColumns(): array
    {
        return ['name', 'value'];
    }

    public function targetFilter(): ?string
    {
        return sprintf("`name` = '%s' AND `locale` = '%s'", self::TERM, $this->locale());
    }

    public function gaps(): array
    {
        return [
            'id' => 'OpenPNE 3 sns_config surrogate key; term_overrides is keyed by (name, locale).',
        ];
    }
}
