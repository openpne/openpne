<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_profile` → OpenPNE 4 `member_profiles`, flattening the nested set: the filter
 * copies single-value roots, checkbox children (root dropped), and a custom date's root, whose value
 * is composed from its children. visibility maps public_flag onto Visibility; 0 / NULL becomes
 * NULL, resolved to the field default at read time as in OpenPNE 3.
 */
class MemberProfileUpgrade extends UpgradeStep
{
    protected string $source = 'member_profile';

    protected string $target = 'member_profiles';

    /**
     * Single-value form types whose root row maps 1:1 (preset date included). `text` is
     * OpenPNE 3's preset single-line type (postal_code/telephone_number); `input` is the
     * custom-field equivalent — ProfileUpgrade folds `text` into `input`, but the source
     * still carries both, so both are matched here.
     */
    private const SINGLE_VALUE_TYPES = "'input', 'text', 'textarea', 'select', 'radio', 'country_select', 'region_select'";

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'profile_id' => Column::source('profile_id'),
            'profile_option_id' => Column::source('profile_option_id'),
            'value' => Column::expr(
                sprintf('CASE WHEN %s THEN %s ELSE `value` END', $this->isCustomDateRoot(), $this->customDateValue()),
                uses: ['value', 'tree_key', 'lft', 'id', 'profile_id'],
            ),
            'value_datetime' => Column::expr($this->normalizedDatetime(), uses: ['value_datetime']),
            // A multi-select stores public_flag only on the root, so a child reads its root's flag
            // (rawPublicFlag); 0 or an invalid flag becomes NULL, the field default.
            'visibility' => Column::expr(
                sprintf(
                    'CASE (%1$s) WHEN 4 THEN %2$d WHEN 1 THEN %3$d WHEN 2 THEN %4$d WHEN 3 THEN %5$d ELSE NULL END',
                    $this->rawPublicFlag(),
                    Visibility::Open->value,
                    Visibility::Members->value,
                    Visibility::Friends->value,
                    Visibility::Private->value,
                ),
                uses: ['public_flag', 'tree_key', 'id'],
            ),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        $ft = $this->profileFormType();
        $name = $this->profileName();
        $root = '`member_profile`.`tree_key` = `member_profile`.`id`';
        $rootOrLegacy = '(`member_profile`.`tree_key` IS NULL OR '.$root.')';

        return implode(' ', [
            '(',
            // single-value root (or legacy untreed row)
            "($rootOrLegacy AND ($ft IN (".self::SINGLE_VALUE_TYPES.") OR ($ft = 'date' AND $name LIKE 'op_preset_%')))",
            // checkbox child (carries the chosen option)
            "OR (`member_profile`.`tree_key` IS NOT NULL AND NOT ($root) AND `member_profile`.`profile_option_id` IS NOT NULL AND $ft = 'checkbox')",
            // custom (non-preset) date root — value composed from its children
            "OR ($root AND $ft = 'date' AND $name NOT LIKE 'op_preset_%')",
            ')',
        ]);
    }

    public function filterColumns(): array
    {
        return ['tree_key', 'id', 'profile_id', 'profile_option_id'];
    }

    public function memberRefs(): array
    {
        return ['member_id'];
    }

    public function gaps(): array
    {
        return [
            'rgt' => 'OpenPNE 3 nested-set right bound; the OpenPNE 4 table is flat (one row per value).',
            'level' => 'OpenPNE 3 nested-set depth; the OpenPNE 4 table is flat.',
            // tree_key / lft are read by the row-selection filter and the custom-date
            // composition, so they are consumed rather than gapped.
        ];
    }

    /** The flag to carry: a multi-select child reads its root's; everyone else their own. */
    private function rawPublicFlag(): string
    {
        return '(CASE WHEN `member_profile`.`tree_key` IS NOT NULL AND `member_profile`.`tree_key` <> `member_profile`.`id`'
            .' THEN (SELECT `r`.`public_flag` FROM '.SourceRef::table('member_profile').' `r` WHERE `r`.`id` = `member_profile`.`tree_key`)'
            .' ELSE `member_profile`.`public_flag` END)';
    }

    private function profileFormType(): string
    {
        return '(SELECT `p`.`form_type` FROM '.SourceRef::table('profile').' `p` WHERE `p`.`id` = `member_profile`.`profile_id`)';
    }

    private function profileName(): string
    {
        return '(SELECT `p`.`name` FROM '.SourceRef::table('profile').' `p` WHERE `p`.`id` = `member_profile`.`profile_id`)';
    }

    private function isCustomDateRoot(): string
    {
        return sprintf(
            '`member_profile`.`tree_key` = `member_profile`.`id` AND %s = \'date\' AND %s NOT LIKE \'op_preset_%%\'',
            $this->profileFormType(),
            $this->profileName(),
        );
    }

    /**
     * A custom date's value as OpenPNE 3 displayed it (MemberProfile::getValue()): a childless root
     * reads its own value, a root with children composes its year/month/day rows, and an incomplete
     * or zero triple is NULL, not a half-date. OpenPNE 3 writes the root either way and adds children
     * only when the field defines options, so the child count tells the shapes apart.
     */
    private function customDateValue(): string
    {
        $y = $this->dateChild(0);
        $m = $this->dateChild(1);
        $d = $this->dateChild(2);

        return "CASE WHEN {$this->dateChildCount()} = 0 THEN `value`"
            ." WHEN {$this->dateChildCount()} = 3 AND {$y} > 0 AND {$m} > 0 AND {$d} > 0"
            .' THEN '.$this->composedDate($y, $m, $d)
            .' ELSE NULL END';
    }

    /**
     * The date OpenPNE 3 shows for a year/month/day triple, overflow included: DateTime::setDate()
     * rolls 2020-02-31 forward to 2020-03-02, and concatenating the parts would migrate a date that
     * does not exist. Offsetting from January 1st avoids MySQL's clamp on a month addition, and the
     * zero-padded year literal avoids MAKEDATE reading a year below 100 as two-digit (OpenPNE 3
     * renders 20 as 0020).
     */
    private function composedDate(string $y, string $m, string $d): string
    {
        return sprintf(
            "DATE_ADD(DATE_ADD(CONCAT(LPAD(%s, 4, '0'), '-01-01'), INTERVAL %s - 1 MONTH), INTERVAL %s - 1 DAY)",
            $y,
            $m,
            $d,
        );
    }

    private function dateChildCount(): string
    {
        return '(SELECT COUNT(*) FROM '.SourceRef::table('member_profile').' `n`'
            .' WHERE `n`.`tree_key` = `member_profile`.`id` AND `n`.`id` <> `member_profile`.`id`)';
    }

    /**
     * OpenPNE 3 stores empty datetimes as the 0001-01-01 / 0000-00-00 sentinels
     * (opDoctrineRecord); map both to NULL. Matched by YEAR (0 or 1) rather than a datetime
     * literal, because comparing against '0000-00-00 00:00:00' throws under strict mode.
     */
    private function normalizedDatetime(): string
    {
        return 'CASE WHEN `value_datetime` IS NOT NULL AND YEAR(`value_datetime`) <= 1 THEN NULL ELSE `value_datetime` END';
    }

    private function dateChild(int $offset): string
    {
        return sprintf(
            '(SELECT `c`.`value` FROM '.SourceRef::table('member_profile').' `c`'
            .' WHERE `c`.`tree_key` = `member_profile`.`id` AND `c`.`id` <> `member_profile`.`id`'
            .' ORDER BY `c`.`lft` LIMIT 1 OFFSET %d)',
            $offset,
        );
    }
}
