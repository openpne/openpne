<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/**
 * OpenPNE 3 default/languageSelecterBox, public because guests switch language too. The view is a functional
 * equivalent of OpenPNE 4 locale switching, not a byte-for-byte port of the OpenPNE 3 template.
 */
class LanguageSelecterBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'languageSelecterBox';
    }

    public function label(): string
    {
        return __('Language Selecter Box');
    }

    public function description(): string
    {
        return __('A language switcher.');
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
