<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
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
 *  - custom (non-preset) date: keep one row (the root) and compose its value from the
 *    year/month/day child rows (ordered by lft); drop the children.
 *
 * public_flag is copied on the OpenPNE 3 scale (1=SNS, 2=Friends, 3=Private, 4=Web), with
 * an invalid 0 normalised to NULL ("use the field default"). Effective resolution
 * (is_edit_public_flag, NULL → profiles.default_public_flag) happens in the read/visibility
 * layer, matching OpenPNE 3's read-time resolution rather than baking it into stored data.
 *
 * The profile/self correlated subqueries name `profile`/`member_profile` unqualified, so
 * (like MemberUpgrade's member_config subqueries) they are not rewritten for a source
 * prefix or a separate source database — acceptable for the fleet (empty prefix, same DB).
 */
class MemberProfileUpgrade extends UpgradeStep
{
    protected string $source = 'member_profile';

    protected string $target = 'member_profiles';

    /** Single-value form types whose root row maps 1:1 (preset date included). */
    private const SINGLE_VALUE_TYPES = "'input', 'textarea', 'select', 'radio', 'country_select', 'region_select'";

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'profile_id' => Column::source('profile_id'),
            'profile_option_id' => Column::source('profile_option_id'),
            'value' => Column::expr(
                sprintf('CASE WHEN %s THEN %s ELSE `value` END', $this->isCustomDateRoot(), $this->composedDate()),
                uses: ['value', 'tree_key', 'lft', 'id', 'profile_id'],
            ),
            'value_datetime' => Column::source('value_datetime'),
            // OpenPNE 3 public-flag scale; 0/invalid → NULL (fall back to the field default).
            // A multi-select stores the flag only on the root, so a child inherits it.
            'public_flag' => Column::expr(
                sprintf('CASE WHEN (%1$s) IN (1, 2, 3, 4) THEN (%1$s) ELSE NULL END', $this->rawPublicFlag()),
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
            .' THEN (SELECT `r`.`public_flag` FROM `member_profile` `r` WHERE `r`.`id` = `member_profile`.`tree_key`)'
            .' ELSE `member_profile`.`public_flag` END)';
    }

    private function profileFormType(): string
    {
        return '(SELECT `p`.`form_type` FROM `profile` `p` WHERE `p`.`id` = `member_profile`.`profile_id`)';
    }

    private function profileName(): string
    {
        return '(SELECT `p`.`name` FROM `profile` `p` WHERE `p`.`id` = `member_profile`.`profile_id`)';
    }

    private function isCustomDateRoot(): string
    {
        return sprintf(
            '`member_profile`.`tree_key` = `member_profile`.`id` AND %s = \'date\' AND %s NOT LIKE \'op_preset_%%\'',
            $this->profileFormType(),
            $this->profileName(),
        );
    }

    /** Y-m-d composed from the date field's year/month/day child rows (ordered by lft). */
    private function composedDate(): string
    {
        return sprintf(
            "CONCAT_WS('-', %s, LPAD(%s, 2, '0'), LPAD(%s, 2, '0'))",
            $this->dateChild(0),
            $this->dateChild(1),
            $this->dateChild(2),
        );
    }

    private function dateChild(int $offset): string
    {
        return sprintf(
            '(SELECT `c`.`value` FROM `member_profile` `c`'
            .' WHERE `c`.`tree_key` = `member_profile`.`id` AND `c`.`id` <> `member_profile`.`id`'
            .' ORDER BY `c`.`lft` LIMIT 1 OFFSET %d)',
            $offset,
        );
    }
}
