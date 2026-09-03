<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** The subject member's friends as a thumbnail grid (OpenPNE 3 friend/friendListBox). */
class FriendListBoxGadget extends GridListGadget
{
    public function name(): string
    {
        return 'friendListBox';
    }

    public function label(): string
    {
        return __('%Friend% List Box');
    }

    public function description(): string
    {
        return __("A list of the member's %friends%.");
    }

    public function component(): string
    {
        return 'gadget.friend-list-box';
    }

    public function feature(): ?Feature
    {
        return Feature::Friend;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'friendList_'.$gadgetId;
    }
}
