<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recently posted diaries by the viewer's friends (OpenPNE 3 diaryFriendList). */
class DiaryFriendListGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryFriendList';
    }

    public function label(): string
    {
        return __('%Diary% %Friend% List');
    }

    public function description(): string
    {
        return __('Recently posted %diaries% from %My_friends%.');
    }

    public function component(): string
    {
        return 'gadget.diary-friend-list';
    }

    /** The friend lens is this kind's whole content, so it goes when friends go. */
    public function dependsOn(string $context): ?Feature
    {
        return Feature::Friend;
    }
}
