<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/** A free-form titled HTML block (OpenPNE 3 default/freeAreaBox). Public on the profile page. */
class FreeAreaGadget extends GadgetKind
{
    public function name(): string
    {
        return 'freeArea';
    }

    public function contexts(): array
    {
        return ['home', 'profile'];
    }

    public function component(): string
    {
        return 'gadget.free-area';
    }

    public function configFields(string $context): array
    {
        return [
            new GadgetConfigField('title', ['ja' => 'タイトル', 'en' => 'Title'], 'input'),
            new GadgetConfigField('value', ['ja' => '内容', 'en' => 'Body'], 'rich_textarea'),
        ];
    }

    public function viewablePrivilege(string $context): int
    {
        return $context === 'profile' ? self::ANYONE : self::MEMBERS;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'freeArea_'.$gadgetId;
    }
}
