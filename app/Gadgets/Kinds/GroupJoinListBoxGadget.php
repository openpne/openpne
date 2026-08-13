<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** The subject member's joined groups as a thumbnail grid (OpenPNE 3 community/joinListBox). */
class GroupJoinListBoxGadget extends GridListGadget
{
    public function name(): string
    {
        return 'groupJoinListBox';
    }

    public function description(): string
    {
        return __('A list of the %communities% the member belongs to.');
    }

    public function component(): string
    {
        return 'gadget.group-join-list-box';
    }

    public function feature(): ?Feature
    {
        return Feature::Group;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'communityList_'.$gadgetId;
    }
}
