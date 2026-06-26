<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** The subject member's joined communities as a thumbnail grid (OpenPNE 3 community/joinListBox). */
class CommunityJoinListBoxGadget extends GridListGadget
{
    public function name(): string
    {
        return 'communityJoinListBox';
    }

    public function description(): string
    {
        return __('A list of the communities the member belongs to.');
    }

    public function component(): string
    {
        return 'gadget.community-join-list-box';
    }

    public function partId(int $gadgetId): ?string
    {
        return 'communityList_'.$gadgetId;
    }
}
