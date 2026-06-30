<?php

namespace App\Upgrade\Steps;

use App\Gadgets\GadgetLayout;
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
        return sprintf('`gadget_id` IN (SELECT `id` FROM %s WHERE `type` IN (%s))', SourceRef::table('gadget'), $this->typeList());
    }

    public function filterColumns(): array
    {
        return ['gadget_id'];
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
