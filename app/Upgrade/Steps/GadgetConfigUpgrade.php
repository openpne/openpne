<?php

namespace App\Upgrade\Steps;

use App\Gadgets\GadgetLayout;
use App\Support\OperatorContentConversion;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `gadget_config` → OpenPNE 4 `gadget_configs` (a gadget's name/value settings). Restricted
 * to configs of the gadgets GadgetUpgrade keeps, so a config never references a dropped gadget.
 */
class GadgetConfigUpgrade extends UpgradeStep
{
    protected string $source = 'gadget_config';

    protected string $target = 'gadget_configs';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'gadget_id' => Column::source('gadget_id'),
            'name' => Column::source('name'),
            'value' => Column::source('value'),
        ];
    }

    public function filter(): ?string
    {
        // The name is this version's own, written after the walk, so a source row that happens to carry it is not copied.
        return sprintf("`gadget_id` IN (SELECT `id` FROM %s WHERE `type` IN (%s)) AND `name` <> '%s'", SourceRef::table('gadget'), $this->typeList(), OperatorContentConversion::GADGET_MARKDOWN);
    }

    public function targetFilter(): ?string
    {
        return sprintf("`name` <> '%s'", OperatorContentConversion::GADGET_MARKDOWN);
    }

    public function filterColumns(): array
    {
        return ['gadget_id', 'name'];
    }

    public function gaps(): array
    {
        return [
            'created_at' => 'OpenPNE 3 gadget_config row timestamp; the OpenPNE 4 config KV does not track per-row timestamps.',
            'updated_at' => 'OpenPNE 3 gadget_config row timestamp; the OpenPNE 4 config KV does not track per-row timestamps.',
        ];
    }

    private function typeList(): string
    {
        return implode(', ', array_map(
            static fn (string $type): string => "'{$type}'",
            array_keys(GadgetLayout::op3TypeMap()),
        ));
    }
}
