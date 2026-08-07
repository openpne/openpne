<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_profile` → OpenPNE 4 `member_profiles`, flattening the nested set.
 *
 * In OpenPNE 3 every value row is a root (tree_key = id) or, for a multi-select, a child
 * (tree_key = root id). The OpenPNE 4 table has no tree columns — one row per value — so
 * which rows are copied depends on the field's form_type (read from `profile`):
 *
 *  - single-value (input/textarea/select/radio/country/region, and preset date): copy the
 *    root row as-is.
 *  - checkbox: copy each child row (it carries profile_option_id); drop the empty root.
 *  - custom (non-preset) date: keep one row (the root) and drop the children. Its value comes
 *    from the year/month/day child rows when it has them, and from the root's own `value`
 *    when it has none — OpenPNE 3 writes the date either way (MemberProfile::getValue()).
 *
 * visibility maps OpenPNE 3's public_flag onto App\Support\Visibility (web=4→Open,
 * SNS=1→Members, friend=2→Friends, private=3→Private); an invalid 0 / NULL becomes NULL
 * ("use the field default"). Effective resolution (is_edit_public_flag, NULL →
 * profiles.default_visibility) happens in the read layer — like OpenPNE 3's read-time
 * resolution — rather than baking it into stored data.
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
            // OpenPNE 3 public_flag → Visibility; 0/invalid → NULL (fall back to the field
            // default). A multi-select stores the flag only on the root, so a child inherits it.
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
     * A custom date field's value, following OpenPNE 3's own precedence (MemberProfile::getValue()):
     * a childless root is read from its own value, and a root with children is composed from the
     * year/month/day rows (ordered by lft) instead.
     *
     * OpenPNE 3 writes the date onto the root either way (MemberProfileForm) and adds children only
     * for the year/month/day options the field defines — a date field with no options has none — so
     * the child count is what says which shape a row is. Reading the root regardless would still be
     * wrong where the two disagree, because the composed value is the one OpenPNE 3 displayed.
     * Children present but not the three complete, non-zero parts is malformed, and becomes NULL
     * rather than a half-date like `2020-03` — again as OpenPNE 3 resolves it.
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
     * The date OpenPNE 3 shows for a year/month/day triple, overflow included: it composes with
     * DateTime::setDate(), so an impossible 2020-02-31 rolls forward to 2020-03-02 rather than being
     * stored as written. Its own form rejects that via checkdate(), so such a triple came from
     * somewhere else — but it is still a date OpenPNE 3 renders, and concatenating the parts would
     * migrate one that does not exist.
     *
     * Offsetting from January 1st is what makes it exact: MySQL clamps a month addition to the end of
     * the target month (2020-01-31 + 1 MONTH = 2020-02-29) and the 1st gives it nothing to clamp.
     * The year is zero-padded into a date literal rather than passed to MAKEDATE, which reads any
     * year below 100 as a two-digit one — 20 would become 2020, while OpenPNE 3 accepts a year of 20
     * (checkdate does) and renders it as 0020.
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
