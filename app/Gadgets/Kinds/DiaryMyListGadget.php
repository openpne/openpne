<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** The viewer's own recently posted diaries (OpenPNE 3 diaryMyList). */
class DiaryMyListGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryMyList';
    }

    public function label(): string
    {
        return __('%Diary% My List');
    }

    public function description(): string
    {
        return __('Your recently posted %diaries%.');
    }

    public function component(): string
    {
        return 'gadget.diary-my-list';
    }
}
