<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/**
 * A locale switcher (OpenPNE 3 default/languageSelecterBox). Lives in the side banner and is public
 * (guests switch language too). Rendered as the functional equivalent of OpenPNE 4 locale switching,
 * not a byte-for-byte template port.
 */
class LanguageSelecterBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'languageSelecterBox';
    }

    public function contexts(): array
    {
        return ['sidebanner'];
    }

    public function component(): string
    {
        return 'gadget.language-selecter-box';
    }

    public function viewablePrivilege(string $context): int
    {
        return self::ANYONE;
    }
}
