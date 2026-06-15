<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/** An announcement HTML block (OpenPNE 3 default/informationBox). Public in the side banner. */
class InformationBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'informationBox';
    }

    public function contexts(): array
    {
        return ['home', 'sidebanner'];
    }

    public function component(): string
    {
        return 'gadget.information-box';
    }

    public function configFields(string $context): array
    {
        return [
            new GadgetConfigField('value', ['ja' => '内容', 'en' => 'Body'], 'rich_textarea'),
        ];
    }

    public function viewablePrivilege(string $context): int
    {
        return $context === 'sidebanner' ? self::ANYONE : self::MEMBERS;
    }
}
