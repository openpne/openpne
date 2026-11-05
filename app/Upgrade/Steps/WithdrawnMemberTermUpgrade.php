<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `sns_config.nickname_of_member_who_does_not_have_credentials` → the OpenPNE 4
 * `withdrawn_member` term, one subclass per locale because OpenPNE 3 showed the one value to every
 * reader (docs/internals/upgrade.md, "Terms"). The OpenPNE 3 default `-` and an empty value are not
 * copied: OpenPNE 4 keeps its own default there.
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
            'value' => Column::source('value'),
        ];
    }

    public function filter(): ?string
    {
        // BINARY: the column's collation reads a full-width or space-padded dash as the default.
        return sprintf("`name` = '%s' AND `value` IS NOT NULL AND `value` <> '' AND `value` <> BINARY '%s'", self::SOURCE_NAME, self::SOURCE_DEFAULT);
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
