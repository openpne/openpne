<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;

/** The subject member's profile values (OpenPNE 3 member/profileListBox). Profile page, public. */
class ProfileListBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'profileListBox';
    }

    public function contexts(): array
    {
        return ['profile'];
    }

    public function component(): string
    {
        return 'gadget.profile-list-box';
    }

    public function viewablePrivilege(string $context): int
    {
        return self::ANYONE;
    }
}
