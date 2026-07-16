<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** Recently posted diaries by the viewer's friends (OpenPNE 3 diaryFriendList). */
class DiaryFriendListGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryFriendList';
    }

    public function description(): string
    {
        return __('Recently posted %diaries% from %My_friends%.');
    }

    public function component(): string
    {
        return 'gadget.diary-friend-list';
    }
}
