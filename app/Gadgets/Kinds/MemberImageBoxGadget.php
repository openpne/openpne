<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/** The subject member's avatar (OpenPNE 3 default/memberImageBox). Public on the profile page. */
class MemberImageBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'memberImageBox';
    }

    public function contexts(): array
    {
        return ['home', 'profile'];
    }

    public function component(): string
    {
        return 'gadget.member-image-box';
    }

    public function viewablePrivilege(string $context): int
    {
        return $context === 'profile' ? self::ANYONE : self::MEMBERS;
    }
}
