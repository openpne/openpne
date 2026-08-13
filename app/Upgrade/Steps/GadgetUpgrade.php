<?php

namespace App\Upgrade\Steps;

use App\Gadgets\GadgetLayout;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `gadget` → OpenPNE 4 `gadgets`: splits `type` into `context` + `zone` (a pair of CASEs
 * from GadgetLayout::op3TypeMap()) and keeps only the types that map into a ported PC context.
 *
 * `name` is carried verbatim except for the builtin kinds OpenPNE 4 renamed (RENAMED_NAMES): an
 * exact-match CASE, never a substring rewrite, so an unknown plugin gadget keeps its own name and
 * stays Unsupported rather than being mangled into a kind it is not.
 */
class GadgetUpgrade extends UpgradeStep
{
    protected string $source = 'gadget';

    protected string $target = 'gadgets';

    /** OpenPNE 3 `gadget.name` => the OpenPNE 4 name for the same builtin kind. */
    private const RENAMED_NAMES = [
        'communityJoinListBox' => 'groupJoinListBox',
        'recentCommunityTopicComment' => 'recentGroupTopicComment',
        'recentCommunityTopicCommentSns' => 'recentGroupTopicCommentSns',
    ];

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'context' => Column::expr($this->splitCase('context'), uses: ['type']),
            'zone' => Column::expr($this->splitCase('zone'), uses: ['type']),
            'name' => Column::expr($this->nameExpr(), uses: ['name']),
            'source_type' => Column::source('type'),
            'sort_order' => Column::source('sort_order'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return sprintf('`type` IN (%s)', $this->typeList());
    }

    public function filterColumns(): array
    {
        return ['type'];
    }

    public function gaps(): array
    {
        return [
            'type (mobile* / smartphone* / dailyNews* rows)' => 'Out of scope: only the PC contexts ('.implode(', ', GadgetLayout::contexts()).') are ported. The filter drops the mobile, smartphone, and daily-news gadget types.',
        ];
    }

    private function typeList(): string
    {
        return implode(', ', array_map(
            static fn (string $type): string => "'{$type}'",
            array_keys(GadgetLayout::op3TypeMap()),
        ));
    }

    /** The renamed builtin kinds, exact-match; every other name passes through untouched. */
    private function nameExpr(): string
    {
        $whens = [];
        foreach (self::RENAMED_NAMES as $op3 => $op4) {
            $whens[] = sprintf("WHEN '%s' THEN '%s'", $op3, $op4);
        }

        return 'CASE `name` '.implode(' ', $whens).' ELSE `name` END';
    }

    private function splitCase(string $field): string
    {
        $whens = [];
        foreach (GadgetLayout::op3TypeMap() as $type => $split) {
            $whens[] = sprintf("WHEN '%s' THEN '%s'", $type, $split[$field]);
        }

        return 'CASE `type` '.implode(' ', $whens).' END';
    }
}
