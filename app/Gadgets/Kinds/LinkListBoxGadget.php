<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/** A titled list of up to ten links (OpenPNE 3 default/linkListBox). Public in the side banner. */
class LinkListBoxGadget extends GadgetKind
{
    private const MAX_LINKS = 10;

    public function name(): string
    {
        return 'linkListBox';
    }

    public function contexts(): array
    {
        return ['home', 'sidebanner'];
    }

    public function component(): string
    {
        return 'gadget.link-list-box';
    }

    public function configFields(string $context): array
    {
        $fields = [new GadgetConfigField('title', ['ja' => 'タイトル', 'en' => 'Title'], 'input')];

        for ($i = 1; $i <= self::MAX_LINKS; $i++) {
            $fields[] = new GadgetConfigField("url{$i}", ['ja' => "URL{$i}", 'en' => "URL {$i}"], 'input');
            $fields[] = new GadgetConfigField("text{$i}", ['ja' => "リンクテキスト{$i}", 'en' => "Link text {$i}"], 'input');
        }

        return $fields;
    }

    public function viewablePrivilege(string $context): int
    {
        return $context === 'sidebanner' ? self::ANYONE : self::MEMBERS;
    }

    // partId stays null: OpenPNE 3's box id here was not gadget-scoped (a loop-variable artifact),
    // so there is no stable per-gadget id to preserve.
}
